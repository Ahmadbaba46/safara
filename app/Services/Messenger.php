<?php

namespace App\Services;

use App\Contracts\WhatsAppClient;
use App\Integrations\WhatsApp\WhatsAppException;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Message;
use App\Models\MessageTemplate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything the bot (or an operator) says goes through here: picks the
 * client's language, fills in the template, uses an approved Meta template
 * when we're outside the 24-hour window, and logs the message.
 */
class Messenger
{
    /** @var array<string, MessageTemplate|null> */
    private array $templates = [];

    public function __construct(private WhatsAppClient $wa) {}

    public function template(string $key): ?MessageTemplate
    {
        return $this->templates[$key] ??= MessageTemplate::query()->where('key', $key)->first();
    }

    public function forgetTemplates(): void
    {
        $this->templates = [];
    }

    /** Render a template body in the client's language. */
    public function render(string $key, array $vars, string $lang): string
    {
        $t = $this->template($key);
        $body = $t ? $t->body($lang) : '{'.$key.'}';

        return self::fill($body, $vars);
    }

    /** @return string[] the template's button titles, filled in */
    public function buttonTitles(string $key, array $vars, string $lang): array
    {
        return array_map(fn ($b) => self::fill($b, $vars), $this->template($key)?->buttons($lang) ?? []);
    }

    public static function fill(string $text, array $vars): string
    {
        $vars['brand'] ??= config('safara.brand');

        $out = preg_replace_callback('/\{(\w+)\}/', fn ($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : '', $text);
        // Tidy up after empty values: "Sorry, , the fare" → "Sorry, the fare"; "Thanks, ." → "Thanks."
        $out = preg_replace(['/,[ \t]*,/', '/,[ \t]*([.!?])/', '/[ \t]+([,.!?])/', '/[ \t]{2,}/'], [',', '$1', '$1', ' '], $out);

        return trim($out);
    }

    /**
     * Send a template. Its buttons (if any) are sent with the ids you pass, in order.
     *
     * @param  string[]  $buttonIds
     */
    public function say(Client $client, ?Booking $booking, string $key, array $vars = [], array $buttonIds = []): ?Message
    {
        $lang = $client->language ?: 'en';
        $body = $this->render($key, $vars, $lang);
        $titles = array_map(fn ($b) => self::fill($b, $vars), $this->template($key)?->buttons($lang) ?? []);

        $buttons = [];
        foreach ($buttonIds as $i => $id) {
            if (isset($titles[$i])) {
                $buttons[$id] = $titles[$i];
            }
        }

        if ($buttons) {
            return $this->deliver($client, $booking, $key, $vars, 'button', $body, ['buttons' => self::pairs($buttons)],
                fn () => $this->wa->sendButtons($client->phone, $body, $buttons));
        }

        return $this->deliver($client, $booking, $key, $vars, 'text', $body, null,
            fn () => $this->wa->sendText($client->phone, $body));
    }

    /** Send a template with custom buttons (id => title). */
    public function sayWithButtons(Client $client, ?Booking $booking, string $key, array $vars, array $buttons): ?Message
    {
        $body = $this->render($key, $vars, $client->language ?: 'en');

        return $this->deliver($client, $booking, $key, $vars, 'button', $body, ['buttons' => self::pairs($buttons)],
            fn () => $this->wa->sendButtons($client->phone, $body, $buttons));
    }

    /** @param array<string, string> $rows id => title */
    public function list(Client $client, ?Booking $booking, string $key, array $vars, string $label, array $rows): ?Message
    {
        $body = $this->render($key, $vars, $client->language ?: 'en');

        return $this->deliver($client, $booking, $key, $vars, 'list', $body, ['label' => $label, 'rows' => self::pairs($rows)],
            fn () => $this->wa->sendList($client->phone, $body, $label, $rows));
    }

    /** A template whose first button is a link (pay links). */
    public function cta(Client $client, ?Booking $booking, string $key, array $vars, string $url): ?Message
    {
        $lang = $client->language ?: 'en';
        $body = $this->render($key, $vars, $lang);
        $label = self::fill($this->template($key)?->buttons($lang)[0] ?? 'Open', $vars);

        return $this->deliver($client, $booking, $key, $vars, 'cta', $body, ['label' => $label, 'url' => $url],
            fn () => $this->wa->sendCta($client->phone, $body, $label, $url));
    }

    public function text(Client $client, ?Booking $booking, string $body, string $sentBy = 'bot'): ?Message
    {
        return $this->deliver($client, $booking, null, [], 'text', $body, null,
            fn () => $this->wa->sendText($client->phone, $body), $sentBy);
    }

    public function document(Client $client, ?Booking $booking, string $path, string $filename, ?string $caption = null): ?Message
    {
        return $this->deliver($client, $booking, null, [], 'document', $caption ?? $filename, ['path' => $path, 'filename' => $filename],
            fn () => $this->wa->sendDocument($client->phone, $path, $filename, $caption));
    }

    /** Alerts to the operator's own WhatsApp. Never throws. */
    public function toOperator(string $text): void
    {
        $phone = config('safara.operator_phone');
        if (! $phone) {
            return;
        }
        try {
            $this->wa->sendText($phone, $text);
        } catch (Throwable $e) {
            Log::warning('Operator alert failed: '.$e->getMessage());
        }
    }

    private function deliver(Client $client, ?Booking $booking, ?string $key, array $vars, string $type, string $body, ?array $payload, callable $send, string $sentBy = 'bot'): ?Message
    {
        $template = $key ? $this->template($key) : null;
        $useMetaTemplate = ! $client->inServiceWindow()
            && $template?->kind === 'template'
            && $template->meta_status === 'approved'
            && $template->meta_name;

        $message = new Message([
            'client_id' => $client->id,
            'booking_id' => $booking?->id,
            'direction' => 'out',
            'type' => $useMetaTemplate ? 'template' : $type,
            'body' => $body,
            'payload' => $payload,
            'sent_by' => $sentBy,
        ]);

        try {
            if ($client->isApp()) {
                // Safara app clients read the messages table directly; nothing to send.
                $message->wa_id = 'app.'.Str::random(16);
            } elseif ($useMetaTemplate) {
                $params = array_map(fn ($v) => self::fill('{'.$v.'}', $vars), $template->variables());
                $message->wa_id = $this->wa->sendTemplate($client->phone, $template->meta_name, $client->language === 'ha' ? 'ha' : 'en', $params);
            } else {
                $message->wa_id = $send();
            }
            $message->status = 'sent';
        } catch (Throwable $e) {
            $message->status = 'failed';
            Log::error('WhatsApp message to '.$client->phone.' failed: '.$e->getMessage());
            if ($booking) {
                $outside = $e instanceof WhatsAppException && $e->outsideWindow();
                $booking->setFlag($outside ? 'Outside 24h window · message not delivered' : 'Message failed to send', 'warn')->save();
                $booking->event('error', $outside ? 'Message not delivered (24-hour window)' : 'Message failed to send', mb_substr($e->getMessage(), 0, 190));
            }
        }

        $message->save();

        return $message;
    }

    /** @return array<int, array{id: string, title: string}> */
    private static function pairs(array $map): array
    {
        $out = [];
        foreach ($map as $id => $title) {
            $out[] = ['id' => (string) $id, 'title' => $title];
        }

        return $out;
    }
}
