<?php

namespace App\Contracts;

/**
 * Outbound WhatsApp messages. Every method returns the provider's message id.
 * Button ids come back in the webhook as the reply id, so keep them short
 * and meaningful ("confirm:yes", "fare:refund").
 */
interface WhatsAppClient
{
    public function sendText(string $to, string $body): string;

    /** @param array<string, string> $buttons id => title (max 3, titles ≤ 20 chars) */
    public function sendButtons(string $to, string $body, array $buttons): string;

    /** @param array<string, string> $rows id => title (max 10, titles ≤ 24 chars) */
    public function sendList(string $to, string $body, string $buttonLabel, array $rows): string;

    /** A message with a single link button ("Pay ₦685,000"). */
    public function sendCta(string $to, string $body, string $label, string $url): string;

    /** @param string $path file on the local disk */
    public function sendDocument(string $to, string $path, string $filename, ?string $caption = null): string;

    /** An approved Meta template, for messages outside the 24-hour window. */
    public function sendTemplate(string $to, string $name, string $language, array $params): string;

    /** @return array{bytes: string, mime: string} */
    public function downloadMedia(string $mediaId): array;
}
