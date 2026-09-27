<?php

namespace App\Integrations\Data;

final class OrderResult
{
    /**
     * @param  string[]  $ticketNumbers
     * @param  array<int, array<string, mixed>>  $segments
     */
    public function __construct(
        public string $orderId,
        public string $bookingReference,
        public array $ticketNumbers = [],
        public array $segments = [],
        public ?float $amount = null,
        public ?string $currency = null,
    ) {}
}
