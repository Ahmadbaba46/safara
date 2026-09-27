<?php

namespace App\Integrations\Data;

final class OfferData
{
    /**
     * @param  array<int, array<string, mixed>>  $segments  flight_number, origin, destination,
     *                                                     origin_city, destination_city, departing_at, arriving_at, carrier
     * @param  string[]  $passengerIds  provider passenger ids, in traveller order
     */
    public function __construct(
        public string $id,
        public string $airline,
        public ?string $airlineCode,
        public string $departOn,         // Y-m-d
        public float $amount,             // in $currency
        public string $currency,
        public int $stops,
        public ?int $durationMinutes,
        public ?string $baggage,
        public array $segments,
        public array $passengerIds,
        public ?string $expiresAt = null, // ISO 8601
    ) {}

    public function summary(): string
    {
        $via = $this->stops === 0 ? 'direct' : 'via '.implode(', ', $this->connectionCities());
        $parts = [$via];
        if ($this->durationMinutes) {
            $parts[] = intdiv($this->durationMinutes, 60).'h '.str_pad((string) ($this->durationMinutes % 60), 2, '0', STR_PAD_LEFT).'m';
        }

        return implode(' · ', $parts);
    }

    /** @return string[] */
    public function connectionCities(): array
    {
        $cities = [];
        $outbound = array_values(array_filter($this->segments, fn ($s) => ($s['slice'] ?? 0) === 0));
        foreach (array_slice($outbound, 0, -1) as $s) {
            $cities[] = $s['destination_city'] ?? $s['destination'];
        }

        return $cities;
    }
}
