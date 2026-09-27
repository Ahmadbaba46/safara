<?php

namespace App\Integrations\Flights;

use App\Contracts\FlightSearch;
use App\Integrations\Data\OfferData;
use App\Integrations\Data\OrderResult;
use App\Support\Iata;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Believable, repeatable offers for local work and tests. Prices depend on
 * the route, date and number of travellers. To rehearse a fare rise after
 * payment, the Simulator sets a "bump" (in NGN) that applies to the next search.
 */
class FakeFlightSearch implements FlightSearch
{
    public const BUMP_KEY = 'fake_flights.bump';

    public const EMPTY_KEY = 'fake_flights.empty';

    private const AIRLINES = [
        ['EgyptAir', 'MS', 'CAI', 'Cairo'],
        ['Ethiopian', 'ET', 'ADD', 'Addis Ababa'],
        ['Turkish Airlines', 'TK', 'IST', 'Istanbul'],
        ['Air Peace', 'P4', 'LOS', 'Lagos'],
        ['Flynas', 'XY', null, null],
    ];

    public static function bumpNextSearch(int $naira): void
    {
        Cache::put(self::BUMP_KEY, $naira, now()->addHour());
    }

    public function search(string $origin, string $destination, string $departOn, ?string $returnOn, array $ages, string $cabin): array
    {
        if (Cache::pull(self::EMPTY_KEY)) {
            return [];
        }

        $bump = (int) Cache::pull(self::BUMP_KEY, 0);
        $pax = max(1, count($ages));
        $domestic = $this->isDomestic($origin) && $this->isDomestic($destination);
        $base = $domestic ? 118000 : 612000;
        $seed = crc32($origin.$destination.$departOn);
        $base += ($seed % 9) * ($domestic ? 3000 : 9500);
        if ($returnOn) {
            $base = (int) round($base * 1.8);
        }

        $offers = [];
        $pick = $domestic ? [3, 3, 3] : [0, 1, 4];
        foreach ($pick as $i => $airlineIndex) {
            [$name, $code, $hub, $hubCity] = self::AIRLINES[$airlineIndex];
            $direct = $domestic || $hub === null || $hub === $origin || $hub === $destination;
            $perPerson = $base + $i * ($domestic ? 7500 : 17300) + $bump;
            $duration = $domestic ? 70 + $i * 15 : ($direct ? 310 : 700 + $i * 85);

            $offers[] = new OfferData(
                id: 'off_fake_'.Str::lower(Str::random(12)),
                airline: $name,
                airlineCode: $code,
                departOn: $departOn,
                amount: (float) ($perPerson * $pax),
                currency: 'NGN',
                stops: $direct ? 0 : 1,
                durationMinutes: $duration,
                baggage: $domestic ? '1 × checked bag' : ($i === 1 ? '2 × checked bag' : '1 × checked bag'),
                segments: $this->segments($origin, $destination, $departOn, $returnOn, $code, $direct ? null : [$hub, $hubCity], $duration, $i),
                passengerIds: array_map(fn ($n) => 'pas_fake_'.$n, range(1, $pax)),
                expiresAt: now()->addMinutes(30)->toIso8601String(),
            );
        }

        return $offers;
    }

    public function getOffer(string $offerId): ?OfferData
    {
        return null; // Fake offers aren't stored; the caller falls back to its saved copy.
    }

    public function createOrder(OfferData $offer, array $passengers, string $contactEmail, string $contactPhone): OrderResult
    {
        $pnr = strtoupper(Str::random(6));
        $pnr = preg_replace('/[^A-Z0-9]/', 'X', $pnr);

        return new OrderResult(
            orderId: 'ord_fake_'.Str::lower(Str::random(12)),
            bookingReference: $pnr,
            ticketNumbers: array_map(fn ($i) => '077'.random_int(1000000000, 9999999999), array_keys($passengers)),
            segments: $offer->segments,
            amount: $offer->amount,
            currency: $offer->currency,
        );
    }

    private function isDomestic(string $code): bool
    {
        return in_array($code, ['ABV', 'LOS', 'KAN', 'KAD', 'PHC', 'ENU', 'SKO', 'MIU', 'YOL', 'ILR', 'QOW', 'BNI', 'CBQ', 'JOS', 'DKA', 'GMO', 'BCU', 'ABB', 'QUO', 'AKR', 'IBA', 'MXJ'], true);
    }

    private function segments(string $from, string $to, string $date, ?string $returnOn, ?string $code, ?array $via, int $duration, int $i): array
    {
        $legs = [];
        $make = function (int $slice, string $a, string $b, string $day, int $depH) use (&$legs, $code, $via, $duration, $i) {
            $dep = new \DateTimeImmutable($day.sprintf(' %02d:%02d', $depH, ($i * 25) % 60));
            if ($via) {
                $firstLeg = (int) round($duration * 0.45);
                $arr1 = $dep->modify("+$firstLeg minutes");
                $dep2 = $arr1->modify('+200 minutes');
                $arr2 = $dep->modify("+$duration minutes");
                $legs[] = ['slice' => $slice, 'flight_number' => $code.(800 + $i * 7), 'carrier' => null, 'origin' => $a, 'origin_city' => Iata::city($a), 'destination' => $via[0], 'destination_city' => $via[1], 'departing_at' => $dep->format('Y-m-d\TH:i:s'), 'arriving_at' => $arr1->format('Y-m-d\TH:i:s')];
                $legs[] = ['slice' => $slice, 'flight_number' => $code.(300 + $i * 11), 'carrier' => null, 'origin' => $via[0], 'origin_city' => $via[1], 'destination' => $b, 'destination_city' => Iata::city($b), 'departing_at' => $dep2->format('Y-m-d\TH:i:s'), 'arriving_at' => $arr2->format('Y-m-d\TH:i:s')];
            } else {
                $arr = $dep->modify("+$duration minutes");
                $legs[] = ['slice' => $slice, 'flight_number' => $code.(100 + $i * 13), 'carrier' => null, 'origin' => $a, 'origin_city' => Iata::city($a), 'destination' => $b, 'destination_city' => Iata::city($b), 'departing_at' => $dep->format('Y-m-d\TH:i:s'), 'arriving_at' => $arr->format('Y-m-d\TH:i:s')];
            }
        };
        $make(0, $from, $to, $date, $via ? 23 : 9 + $i * 3);
        if ($returnOn) {
            $make(1, $to, $from, $returnOn, $via ? 14 : 16);
        }

        return $legs;
    }
}
