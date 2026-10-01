<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Integrations\Flights\ManualFlightSearch;
use App\Models\Booking;
use App\Models\Offer;
use App\Models\Payment;
use App\Support\Iata;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Throwable;

class QuoteService
{
    public function __construct(
        private OfferService $offers,
        private Settings $settings,
        private Messenger $messenger,
        private PaymentService $payments,
    ) {}

    /**
     * Search fares, price the cheapest, open a pay link and send the quote.
     *
     * @param  string  $messageKey  quote | hold_expired
     */
    public function quote(Booking $booking, string $messageKey = 'quote'): bool
    {
        $client = $booking->client;
        $previous = $booking->quote_amount;

        if (ManualFlightSearch::enabled()) {
            return $this->requestManualQuote($booking);
        }

        try {
            $offers = $this->offers->search($booking);
        } catch (Throwable $e) {
            Log::error("Search failed for {$booking->reference}: ".$e->getMessage());
            $booking->setFlag('Flight search failed · check it', 'warn')->save();
            $booking->event('error', 'Flight search failed', mb_substr($e->getMessage(), 0, 190));
            $this->messenger->say($client, $booking, 'search_delay');
            $this->messenger->toOperator("{$booking->reference}: flight search failed — ".mb_substr($e->getMessage(), 0, 120));

            return false;
        }

        if ($offers->isEmpty()) {
            $booking->update(['status' => BookingStatus::CollectingTrip, 'depart_on' => null]);
            $booking->setFlag('No flights found · asked for a new date', 'warn')->save();
            $booking->event('search', 'No flights found', $booking->routeCodes());
            $this->messenger->say($client, $booking, 'no_flights', ['route' => $booking->routeNames()]);

            return false;
        }

        $cheapest = $offers->first();
        $booking->offers()->update(['selected' => false]);
        $cheapest->update(['selected' => true]);

        $this->sendQuote($booking, $cheapest, $this->settings->pricing()->quote($cheapest->amount), $messageKey, $previous);

        return true;
    }

    /** Open the pay link for a chosen offer at $price and tell the client. */
    private function sendQuote(Booking $booking, Offer $offer, int $price, string $messageKey, ?int $previous): void
    {
        $client = $booking->client;
        $holdUntil = now()->addMinutes($this->settings->holdMinutes());

        $booking->fill([
            'status' => BookingStatus::AwaitingPayment,
            'fare_amount' => $offer->amount,
            'quote_amount' => $price,
            'quoted_at' => now(),
            'hold_expires_at' => $holdUntil,
        ])->setFlag(null)->save();

        $payment = $this->payments->openLink($booking, $price, 'fare', $holdUntil);

        $booking->event('quote', 'Quote sent · '.Money::format($price), 'Pay link valid for '.$this->settings->holdLabel());

        $vars = [
            'name' => $booking->passports()->first()?->firstName() ?: '',
            'route' => $booking->routeNames(),
            'route_codes' => $booking->routeCodes(),
            'date' => $booking->depart_on->format('D j M'),
            'return' => $booking->return_on?->format('D j M') ?? '',
            'travellers' => $booking->travellersLabel(),
            'amount' => Money::format($price),
            'hold' => $this->settings->holdLabel(),
            'time' => $booking->hold_expires_at->timezone(config('safara.timezone'))->format('H:i'),
            'old_amount' => $previous ? Money::format($previous) : '',
            'origin' => Iata::city($booking->origin),
            'destination' => Iata::city($booking->destination),
        ];
        if ($messageKey === 'hold_expired') {
            $vars['time'] = $booking->stateGet('expired_at_label', '');
            $vars['change'] = $previous && $price !== $previous
                ? ($price > $previous ? Money::format($price - $previous).' more than before.' : Money::format($previous - $price).' less than before.')
                : '';
        }

        $this->messenger->cta($client, $booking, $messageKey, $vars, $payment->url());
    }

    // ---- operator ticketing (no flight API) ----------------------------------

    /** Park the booking until a person sends a price from the desk. */
    private function requestManualQuote(Booking $booking): bool
    {
        $booking->update(['status' => BookingStatus::AwaitingQuote]);
        $booking->payments()->where('kind', 'charge')->where('status', 'open')->update(['status' => 'expired']);
        $booking->setFlag('Needs your quote', 'warn')->save();
        $booking->event('quote', 'Waiting for your quote', $booking->routeCodes().' · '.$booking->travellersLabel());

        $this->messenger->say($booking->client, $booking, 'quote_pending');
        $this->messenger->toOperator("{$booking->reference} ({$booking->client->displayName()}): ready for your quote. ".route('bookings.show', $booking));

        return true;
    }

    /**
     * The operator's price: record it as the offer and send the pay link.
     *
     * @param  array{airline: string, price: int, cost?: ?int, baggage?: ?string, summary?: ?string, segments?: array}  $data
     */
    public function sendManualQuote(Booking $booking, array $data): void
    {
        $price = (int) $data['price'];
        $cost = (int) ($data['cost'] ?? 0) ?: $price;
        $previous = $booking->quote_amount;

        $booking->offers()->update(['selected' => false]);
        $offer = $booking->offers()->create([
            'provider_offer_id' => 'off_manual_'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(10)),
            'batch' => 'manual-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8)),
            'airline' => $data['airline'],
            'depart_on' => $booking->depart_on,
            'summary' => $data['summary'] ?: ($booking->return_on ? 'Return' : 'One way'),
            'stops' => 0,
            'baggage' => $data['baggage'] ?? null,
            'amount' => $cost,
            'original_amount' => $cost,
            'original_currency' => 'NGN',
            'segments' => $data['segments'] ?? [],
            'passenger_ids' => [],
            'selected' => true,
        ]);

        $booking->event('quote', 'Quote entered by '.(request()->user()?->name ?? 'the desk'), $data['airline'].' · '.Money::format($price));
        $this->sendQuote($booking, $offer, $price, $previous ? 'hold_expired' : 'quote', $previous);
    }

    /** The client came back after the price hold ended: check again and re-quote. */
    public function requote(Booking $booking): bool
    {
        $label = $booking->hold_expires_at?->timezone(config('safara.timezone'))->format('H:i');
        $booking->stateSet('expired_at_label', $booking->hold_expires_at?->isToday() ? 'at '.$label : 'yesterday at '.$label)->save();

        return $this->quote($booking, 'hold_expired');
    }

    /** Resend the current pay link (client asked again while it's still open). */
    public function remind(Booking $booking, Payment $payment): void
    {
        $this->messenger->cta($booking->client, $booking, 'pay_reminder', [
            'amount' => Money::format($payment->amount),
            'time' => $payment->expires_at?->timezone(config('safara.timezone'))->format('H:i') ?? '',
        ], $payment->url());
    }
}
