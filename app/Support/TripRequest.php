<?php

namespace App\Support;

/** What the client asked for, as far as we understood it. */
final class TripRequest
{
    public function __construct(
        public ?string $origin = null,
        public ?string $destination = null,
        public ?string $departOn = null,   // Y-m-d
        public ?string $returnOn = null,   // Y-m-d
        public ?int $travellers = null,
        public ?string $language = null,   // en | ha, when the text makes it obvious
    ) {}

    /** Fill in only what this request knows and the other doesn't. */
    public function mergeInto(array $current): array
    {
        return [
            'origin' => $this->origin ?? ($current['origin'] ?? null),
            'destination' => $this->destination ?? ($current['destination'] ?? null),
            'depart_on' => $this->departOn ?? ($current['depart_on'] ?? null),
            'return_on' => $this->returnOn ?? ($current['return_on'] ?? null),
            'travellers' => $this->travellers ?? ($current['travellers'] ?? null),
        ];
    }

    public function isEmpty(): bool
    {
        return ! $this->origin && ! $this->destination && ! $this->departOn && ! $this->travellers;
    }

    public function toArray(): array
    {
        return [
            'origin' => $this->origin, 'destination' => $this->destination,
            'depart_on' => $this->departOn, 'return_on' => $this->returnOn,
            'travellers' => $this->travellers, 'language' => $this->language,
        ];
    }
}
