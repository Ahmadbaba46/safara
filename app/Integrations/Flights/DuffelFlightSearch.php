<?php

namespace App\Integrations\Flights;

use App\Contracts\FlightSearch;
use App\Integrations\Data\OfferData;
use App\Integrations\Data\OrderResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Duffel Flights API: offer requests → offers → instant orders paid from the
 * Duffel balance. https://duffel.com/docs/api
 */
class DuffelFlightSearch implements FlightSearch
{
    public function __construct(private array $config) {}

    private function http(): PendingRequest
    {
        if (empty($this->config['token'])) {
            throw new RuntimeException('Set DUFFEL_ACCESS_TOKEN to search real flights.');
        }

        return Http::baseUrl(rtrim($this->config['url'], '/'))
            ->withToken($this->config['token'])
            ->withHeaders(['Duffel-Version' => $this->config['version'], 'Accept-Encoding' => 'gzip'])
            ->acceptJson()->asJson()->timeout(45);
    }

    public function search(string $origin, string $destination, string $departOn, ?string $returnOn, array $ages, string $cabin): array
    {
        $slices = [['origin' => $origin, 'destination' => $destination, 'departure_date' => $departOn]];
        if ($returnOn) {
            $slices[] = ['origin' => $destination, 'destination' => $origin, 'departure_date' => $returnOn];
        }
        $passengers = array_map(fn ($age) => $age === null ? ['type' => 'adult'] : ['age' => (int) $age], $ages ?: [null]);

        $response = $this->http()->post('/air/offer_requests?return_offers=true&supplier_timeout=20000', [
            'data' => [
                'slices' => $slices,
                'passengers' => $passengers,
                'cabin_class' => $cabin,
                'max_connections' => 1,
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Duffel search failed: '.$this->error($response->json()));
        }

        $offers = array_map(fn ($o) => $this->toOffer($o), $response->json('data.offers', []));
        usort($offers, fn (OfferData $a, OfferData $b) => $a->amount <=> $b->amount);

        return array_slice($offers, 0, 10);
    }

    public function getOffer(string $offerId): ?OfferData
    {
        $response = $this->http()->get('/air/offers/'.$offerId, ['return_available_services' => 'false']);
        if ($response->status() === 404 || $response->status() === 422) {
            return null;
        }
        if ($response->failed()) {
            throw new RuntimeException('Duffel offer lookup failed: '.$this->error($response->json()));
        }

        return $this->toOffer($response->json('data'));
    }

    /**
     * Each passenger: given_name, family_name, born_on (Y-m-d), gender (m|f), title (mr|ms),
     * passport_number, passport_country (alpha-2), passport_expires_on (Y-m-d).
     */
    public function createOrder(OfferData $offer, array $passengers, string $contactEmail, string $contactPhone): OrderResult
    {
        $people = [];
        foreach ($passengers as $i => $p) {
            $person = [
                'id' => $offer->passengerIds[$i] ?? throw new RuntimeException('Offer has fewer passengers than travellers.'),
                'title' => $p['title'],
                'gender' => $p['gender'],
                'given_name' => $p['given_name'],
                'family_name' => $p['family_name'],
                'born_on' => $p['born_on'],
                'email' => $contactEmail,
                'phone_number' => '+'.ltrim($contactPhone, '+'),
            ];
            if (! empty($p['passport_number'])) {
                $person['identity_documents'] = [[
                    'type' => 'passport',
                    'unique_identifier' => $p['passport_number'],
                    'issuing_country_code' => $p['passport_country'],
                    'expires_on' => $p['passport_expires_on'],
                ]];
            }
            $people[] = $person;
        }

        $response = $this->http()->post('/air/orders', [
            'data' => [
                'type' => 'instant',
                'selected_offers' => [$offer->id],
                'passengers' => $people,
                'payments' => [[
                    'type' => 'balance',
                    'currency' => $offer->currency,
                    'amount' => number_format($offer->amount, 2, '.', ''),
                ]],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Duffel order failed: '.$this->error($response->json()));
        }

        $order = $response->json('data');
        $tickets = collect($order['documents'] ?? [])->where('type', 'electronic_ticket')->pluck('unique_identifier')->all();

        return new OrderResult(
            orderId: $order['id'],
            bookingReference: $order['booking_reference'],
            ticketNumbers: $tickets,
            segments: $this->segments($order['slices'] ?? []),
            amount: isset($order['total_amount']) ? (float) $order['total_amount'] : null,
            currency: $order['total_currency'] ?? null,
        );
    }

    private function toOffer(array $o): OfferData
    {
        $slices = $o['slices'] ?? [];
        $outbound = $slices[0] ?? [];
        $segments = $this->segments($slices);
        $firstSeg = $outbound['segments'][0] ?? [];

        $bags = collect($firstSeg['passengers'][0]['baggages'] ?? [])->where('type', 'checked')->sum('quantity');

        return new OfferData(
            id: $o['id'],
            airline: $o['owner']['name'] ?? ($firstSeg['marketing_carrier']['name'] ?? 'Airline'),
            airlineCode: $o['owner']['iata_code'] ?? null,
            departOn: substr($firstSeg['departing_at'] ?? now()->toDateString(), 0, 10),
            amount: (float) $o['total_amount'],
            currency: $o['total_currency'],
            stops: max(0, count($outbound['segments'] ?? []) - 1),
            durationMinutes: self::minutes($outbound['duration'] ?? null),
            baggage: $bags > 0 ? $bags.' × checked bag' : 'Cabin bag only',
            segments: $segments,
            passengerIds: array_column($o['passengers'] ?? [], 'id'),
            expiresAt: $o['expires_at'] ?? null,
        );
    }

    private function segments(array $slices): array
    {
        $out = [];
        foreach ($slices as $i => $slice) {
            foreach ($slice['segments'] ?? [] as $s) {
                $out[] = [
                    'slice' => $i,
                    'flight_number' => ($s['marketing_carrier']['iata_code'] ?? '').($s['marketing_carrier_flight_number'] ?? ''),
                    'carrier' => $s['marketing_carrier']['name'] ?? null,
                    'origin' => $s['origin']['iata_code'] ?? null,
                    'origin_city' => $s['origin']['city_name'] ?? ($s['origin']['name'] ?? null),
                    'destination' => $s['destination']['iata_code'] ?? null,
                    'destination_city' => $s['destination']['city_name'] ?? ($s['destination']['name'] ?? null),
                    'departing_at' => $s['departing_at'] ?? null,
                    'arriving_at' => $s['arriving_at'] ?? null,
                ];
            }
        }

        return $out;
    }

    /** ISO 8601 duration "PT11H40M" / "P1DT2H" → minutes */
    public static function minutes(?string $iso): ?int
    {
        if (! $iso || ! preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?)?$/', $iso, $m)) {
            return null;
        }

        return ((int) ($m[1] ?? 0)) * 1440 + ((int) ($m[2] ?? 0)) * 60 + (int) ($m[3] ?? 0);
    }

    private function error(?array $json): string
    {
        $e = $json['errors'][0] ?? null;

        return $e ? trim(($e['title'] ?? '').': '.($e['message'] ?? '')) : 'unknown error';
    }
}
