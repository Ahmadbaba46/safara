<?php

namespace App\Contracts;

use App\Integrations\Data\OfferData;
use App\Integrations\Data\OrderResult;

interface FlightSearch
{
    /**
     * @param  int[]  $ages  one entry per traveller (their age on the travel date)
     * @return OfferData[] cheapest first
     */
    public function search(string $origin, string $destination, string $departOn, ?string $returnOn, array $ages, string $cabin): array;

    /** Re-fetch an offer to confirm it's still bookable and at what price. Null if gone. */
    public function getOffer(string $offerId): ?OfferData;

    /**
     * Book and pay (from the Duffel balance) for an offer.
     *
     * @param  array<int, array<string, mixed>>  $passengers  see DuffelFlightSearch::createOrder
     */
    public function createOrder(OfferData $offer, array $passengers, string $contactEmail, string $contactPhone): OrderResult;
}
