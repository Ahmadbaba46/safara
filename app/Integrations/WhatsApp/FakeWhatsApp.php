<?php

namespace App\Integrations\WhatsApp;

use App\Contracts\WhatsAppClient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sends nothing. Every message is still written to the messages table by
 * the Messenger, so the desk chat view and the Simulator show exactly what a
 * client would have received. $sent is handy in tests.
 */
class FakeWhatsApp implements WhatsAppClient
{
    /** @var array<int, array<string, mixed>> */
    public static array $sent = [];

    private function record(string $to, string $type, array $data): string
    {
        $id = 'fake.'.Str::random(16);
        static::$sent[] = ['id' => $id, 'to' => $to, 'type' => $type] + $data;

        return $id;
    }

    public function sendText(string $to, string $body): string
    {
        return $this->record($to, 'text', ['body' => $body]);
    }

    public function sendButtons(string $to, string $body, array $buttons): string
    {
        return $this->record($to, 'buttons', ['body' => $body, 'buttons' => $buttons]);
    }

    public function sendList(string $to, string $body, string $buttonLabel, array $rows): string
    {
        return $this->record($to, 'list', ['body' => $body, 'label' => $buttonLabel, 'rows' => $rows]);
    }

    public function sendCta(string $to, string $body, string $label, string $url): string
    {
        return $this->record($to, 'cta', ['body' => $body, 'label' => $label, 'url' => $url]);
    }

    public function sendDocument(string $to, string $path, string $filename, ?string $caption = null): string
    {
        return $this->record($to, 'document', ['path' => $path, 'filename' => $filename, 'caption' => $caption]);
    }

    public function sendTemplate(string $to, string $name, string $language, array $params): string
    {
        return $this->record($to, 'template', ['name' => $name, 'language' => $language, 'params' => $params]);
    }

    /** The Simulator stores uploaded photos under simulator/<media id>. */
    public function downloadMedia(string $mediaId): array
    {
        $path = 'simulator/'.basename($mediaId);

        return [
            'bytes' => Storage::disk('local')->exists($path) ? Storage::disk('local')->get($path) : 'SAMPLE',
            'mime' => 'image/jpeg',
        ];
    }

    public static function reset(): void
    {
        static::$sent = [];
    }
}
