<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
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

        $price = $this->settings->pricing()->quote($cheapest->amount);
        $holdUntil = now()->addMinutes($this->settings->holdMinutes());

        $booking->fill([
            'status' => BookingStatus::AwaitingPayment,
            'fare_amount' => $cheapest->amount,
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

        return true;
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
