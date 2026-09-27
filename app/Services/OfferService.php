<?php

namespace App\Services;

use App\Contracts\FlightSearch;
use App\Integrations\Data\OfferData;
use App\Models\Booking;
use App\Models\Offer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Searches flights for a booking and keeps the results as Offer rows. */
class OfferService
{
    public function __construct(private FlightSearch $flights, private Settings $settings) {}

    /**
     * @return Collection<int, Offer> cheapest first (empty if nothing found)
     */
    public function search(Booking $booking, ?Carbon $departOn = null, string $batchPrefix = 'q'): Collection
    {
        $depart = $departOn ?? $booking->depart_on;
        $return = $booking->return_on;
        if ($return && $departOn && $booking->depart_on) {
            // Keep the same trip length when trying another date.
            $return = $booking->return_on->copy()->addDays((int) round($booking->depart_on->diffInDays($departOn, false)));
        }

        $results = $this->flights->search(
            $booking->origin,
            $booking->destination,
            $depart->toDateString(),
            $return?->toDateString(),
            $this->ages($booking, $depart),
            $booking->cabin_class ?: $this->settings->get('cabin_class', 'economy'),
        );

        $batch = $batchPrefix.'-'.Str::lower(Str::random(10));
        $saved = collect();
        foreach ($results as $o) {
            $saved->push($this->store($booking, $o, $batch));
        }

        return $saved->sortBy('amount')->values();
    }

    public function store(Booking $booking, OfferData $o, string $batch): Offer
    {
        return $booking->offers()->create([
            'provider_offer_id' => $o->id,
            'batch' => $batch,
            'airline' => $o->airline,
            'airline_code' => $o->airlineCode,
            'depart_on' => $o->departOn,
            'summary' => $o->summary(),
            'stops' => $o->stops,
            'duration_minutes' => $o->durationMinutes,
            'baggage' => $o->baggage,
            'amount' => $this->settings->toNaira($o->amount, $o->currency),
            'original_amount' => $o->amount,
            'original_currency' => $o->currency,
            'segments' => $o->segments,
            'passenger_ids' => $o->passengerIds,
            'expires_at' => $o->expiresAt ? Carbon::parse($o->expiresAt) : null,
        ]);
    }

    /**
     * The provider's current version of an offer: refreshed price, or null
     * when it's gone. Fake offers can't be refreshed, so the saved copy stands.
     */
    public function refresh(Offer $offer): ?OfferData
    {
        $fresh = $this->flights->getOffer($offer->provider_offer_id);
        if ($fresh) {
            $offer->update([
                'amount' => $this->settings->toNaira($fresh->amount, $fresh->currency),
                'original_amount' => $fresh->amount,
                'original_currency' => $fresh->currency,
                'passenger_ids' => $fresh->passengerIds,
                'expires_at' => $fresh->expiresAt ? Carbon::parse($fresh->expiresAt) : null,
            ]);

            return $fresh;
        }
        if (str_starts_with($offer->provider_offer_id, 'off_fake_')) {
            return $this->toData($offer);
        }

        return null;
    }

    public function toData(Offer $offer): OfferData
    {
        return new OfferData(
            id: $offer->provider_offer_id,
            airline: $offer->airline,
            airlineCode: $offer->airline_code,
            departOn: $offer->depart_on->toDateString(),
            amount: (float) $offer->original_amount,
            currency: $offer->original_currency,
            stops: $offer->stops,
            durationMinutes: $offer->duration_minutes,
            baggage: $offer->baggage,
            segments: $offer->segments ?? [],
            passengerIds: $offer->passenger_ids ?? [],
            expiresAt: $offer->expires_at?->toIso8601String(),
        );
    }

    /**
     * Cheapest offer on nearby dates that costs no more than $budget.
     */
    public function alternativeWithin(Booking $booking, int $budget): ?Offer
    {
        $best = null;
        foreach ([1, -1, 2, 3] as $shift) {
            $date = $booking->depart_on->copy()->addDays($shift);
            if ($date->isPast()) {
                continue;
            }
            try {
                $offers = $this->search($booking, $date, 'alt');
            } catch (\Throwable) {
                continue;
            }
            $fit = $offers->first(fn (Offer $o) => $o->amount <= $budget);
            if ($fit && (! $best || $fit->amount < $best->amount)) {
                $best = $fit;
            }
        }

        return $best;
    }

    /** @return int[] each traveller's age on the travel date (null = adult, unknown) */
    private function ages(Booking $booking, Carbon $on): array
    {
        $passports = $booking->passports()->get();
        $count = max((int) $booking->travellers, $passports->count(), 1);
        $ages = [];
        for ($i = 0; $i < $count; $i++) {
            $ages[] = $passports->get($i)?->ageOn($on);
        }

        return $ages;
    }
}
