<?php

namespace App\Integrations\WhatsApp;

use App\Contracts\WhatsAppClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** WhatsApp Cloud API (graph.facebook.com). */
class MetaWhatsApp implements WhatsAppClient
{
    public function __construct(private array $config) {}

    private function http(): PendingRequest
    {
        if (empty($this->config['token']) || empty($this->config['phone_number_id'])) {
            throw new RuntimeException('WhatsApp is not configured: set META_WHATSAPP_TOKEN and META_WHATSAPP_PHONE_NUMBER_ID.');
        }

        return Http::withToken($this->config['token'])->acceptJson()->timeout(20)->retry(2, 500, throw: false);
    }

    private function url(string $path): string
    {
        return rtrim($this->config['graph_url'], '/').'/'.$this->config['graph_version'].'/'.ltrim($path, '/');
    }

    private function send(string $to, array $message): string
    {
        $response = $this->http()->post($this->url($this->config['phone_number_id'].'/messages'), array_merge([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
        ], $message));

        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->body();
            $code = $response->json('error.code');
            throw new WhatsAppException("WhatsApp send failed ($code): $error", (int) $code);
        }

        return (string) $response->json('messages.0.id');
    }

    public function sendText(string $to, string $body): string
    {
        return $this->send($to, ['type' => 'text', 'text' => ['body' => $body, 'preview_url' => false]]);
    }

    public function sendButtons(string $to, string $body, array $buttons): string
    {
        $items = [];
        foreach (array_slice($buttons, 0, 3, true) as $id => $title) {
            $items[] = ['type' => 'reply', 'reply' => ['id' => (string) $id, 'title' => Str::limit($title, 20, '')]];
        }

        return $this->send($to, ['type' => 'interactive', 'interactive' => [
            'type' => 'button',
            'body' => ['text' => Str::limit($body, 1024, '…')],
            'action' => ['buttons' => $items],
        ]]);
    }

    public function sendList(string $to, string $body, string $buttonLabel, array $rows): string
    {
        $items = [];
        foreach (array_slice($rows, 0, 10, true) as $id => $title) {
            $items[] = ['id' => (string) $id, 'title' => Str::limit($title, 24, '')];
        }

        return $this->send($to, ['type' => 'interactive', 'interactive' => [
            'type' => 'list',
            'body' => ['text' => Str::limit($body, 1024, '…')],
            'action' => ['button' => Str::limit($buttonLabel, 20, ''), 'sections' => [['title' => Str::limit($buttonLabel, 24, ''), 'rows' => $items]]],
        ]]);
    }

    public function sendCta(string $to, string $body, string $label, string $url): string
    {
        return $this->send($to, ['type' => 'interactive', 'interactive' => [
            'type' => 'cta_url',
            'body' => ['text' => Str::limit($body, 1024, '…')],
            'action' => ['name' => 'cta_url', 'parameters' => ['display_text' => Str::limit($label, 20, ''), 'url' => $url]],
        ]]);
    }

    public function sendDocument(string $to, string $path, string $filename, ?string $caption = null): string
    {
        $upload = $this->http()
            ->attach('file', Storage::disk('local')->get($path), $filename, ['Content-Type' => 'application/pdf'])
            ->post($this->url($this->config['phone_number_id'].'/media'), ['messaging_product' => 'whatsapp', 'type' => 'application/pdf']);

        if ($upload->failed() || ! $upload->json('id')) {
            throw new WhatsAppException('WhatsApp media upload failed: '.($upload->json('error.message') ?? $upload->body()));
        }

        return $this->send($to, ['type' => 'document', 'document' => array_filter([
            'id' => $upload->json('id'),
            'filename' => $filename,
            'caption' => $caption,
        ])]);
    }

    public function sendTemplate(string $to, string $name, string $language, array $params): string
    {
        $components = $params ? [[
            'type' => 'body',
            'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], array_values($params)),
        ]] : [];

        return $this->send($to, ['type' => 'template', 'template' => [
            'name' => $name,
            'language' => ['code' => $language],
            'components' => $components,
        ]]);
    }

    public function downloadMedia(string $mediaId): array
    {
        $meta = $this->http()->get($this->url($mediaId));
        if ($meta->failed() || ! $meta->json('url')) {
            throw new WhatsAppException('Could not look up WhatsApp media '.$mediaId);
        }
        $file = $this->http()->get($meta->json('url'));
        if ($file->failed()) {
            throw new WhatsAppException('Could not download WhatsApp media '.$mediaId);
        }

        return ['bytes' => $file->body(), 'mime' => $meta->json('mime_type') ?? 'image/jpeg'];
    }

    /** Validate Meta's X-Hub-Signature-256 header. */
    public static function validSignature(string $payload, ?string $header, ?string $secret): bool
    {
        if (! $secret) {
            return app()->environment(['local', 'testing']);
        }
        if (! $header || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $payload, $secret), $header);
    }
}
