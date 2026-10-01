<?php

namespace App\Integrations\Flights;

use App\Contracts\FlightSearch;
use App\Integrations\Data\OfferData;
use App\Integrations\Data\OrderResult;
use RuntimeException;

/**
 * "Operator ticketing": there is no flight API. The bot collects the trip,
 * the passports and the payment; a person on the desk quotes the price and
 * books the ticket with their own agent or airline, then records the PNR.
 */
class ManualFlightSearch implements FlightSearch
{
    public static function enabled(): bool
    {
        return config('safara.drivers.flights') === 'manual';
    }

    public function search(string $origin, string $destination, string $departOn, ?string $returnOn, array $ages, string $cabin): array
    {
        return [];
    }

    public function getOffer(string $offerId): ?OfferData
    {
        return null;
    }

    public function createOrder(OfferData $offer, array $passengers, string $contactEmail, string $contactPhone): OrderResult
    {
        throw new RuntimeException('Manual ticketing: book with your agent, then record the PNR on the booking page.');
    }
}
