<?php

namespace App\Services;

/** One message from a client, whatever channel it came through. */
final class InboundMessage
{
    public function __construct(
        public string $from,              // phone, digits only
        public string $type,              // text | image | reply | other
        public ?string $text = null,
        public ?string $replyId = null,   // button / list id
        public ?string $mediaId = null,
        public ?string $mime = null,
        public ?string $waId = null,
        public ?string $profileName = null,
    ) {}

    /** Build from one entry of a WhatsApp Cloud API webhook's messages[]. */
    public static function fromMeta(array $m, ?string $profileName = null): self
    {
        $type = $m['type'] ?? 'other';
        $from = preg_replace('/\D/', '', (string) ($m['from'] ?? ''));

        return match ($type) {
            'text' => new self($from, 'text', $m['text']['body'] ?? '', waId: $m['id'] ?? null, profileName: $profileName),
            'image' => new self($from, 'image', $m['image']['caption'] ?? null, mediaId: $m['image']['id'] ?? null, mime: $m['image']['mime_type'] ?? 'image/jpeg', waId: $m['id'] ?? null, profileName: $profileName),
            // Passports sent as a file rather than a photo.
            'document' => str_starts_with($m['document']['mime_type'] ?? '', 'image/')
                ? new self($from, 'image', $m['document']['caption'] ?? null, mediaId: $m['document']['id'] ?? null, mime: $m['document']['mime_type'], waId: $m['id'] ?? null, profileName: $profileName)
                : new self($from, 'other', waId: $m['id'] ?? null, profileName: $profileName),
            'interactive' => new self(
                $from, 'reply',
                $m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? null,
                replyId: $m['interactive']['button_reply']['id'] ?? $m['interactive']['list_reply']['id'] ?? null,
                waId: $m['id'] ?? null, profileName: $profileName,
            ),
            // Quick-reply buttons on approved templates arrive as "button".
            'button' => new self($from, 'reply', $m['button']['text'] ?? null, replyId: $m['button']['payload'] ?? null, waId: $m['id'] ?? null, profileName: $profileName),
            default => new self($from, 'other', waId: $m['id'] ?? null, profileName: $profileName),
        };
    }

    public function words(): string
    {
        return mb_strtolower(trim((string) $this->text));
    }
}
