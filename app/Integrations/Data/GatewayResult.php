<?php

namespace App\Integrations\Data;

final class GatewayResult
{
    public function __construct(
        public bool $ok,                    // paid / refunded
        public ?string $reference = null,   // our payment reference (from webhooks)
        public ?string $providerRef = null,
        public ?string $method = null,
        public ?int $amount = null,         // NGN, when the provider tells us
        public ?string $message = null,
    ) {}
}
