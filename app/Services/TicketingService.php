<?php

namespace App\Services;

use App\Contracts\FlightSearch;
use App\Enums\BookingStatus;
use App\Integrations\Flights\ManualFlightSearch;
use App\Models\Booking;
use App\Models\Offer;
use App\Models\Payment;
use App\Support\FareDecision;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Throwable;

class TicketingService
{
    public function __construct(
        private OfferService $offers,
        private FlightSearch $flights,
        private Settings $settings,
        private Messenger $messenger,
        private PaymentService $payments,
        private PassportService $passports,
        private TicketPdf $pdf,
    ) {}

    /** Runs once per confirmed payment. */
    public function afterPayment(Payment $payment): void
    {
        $booking = $payment->booking()->with('client')->first();

        if ($payment->purpose === 'difference') {
            $this->messenger->say($booking->client, $booking, 'payment_received', ['amount' => Money::format($payment->amount)]);
            $offer = $booking->selectedOffer();
            $offer ? $this->issue($booking, $offer, force: true) : $this->review($booking, 'Difference paid, but the chosen flight is missing');

            return;
        }

        if (in_array($booking->status, [BookingStatus::Ticketed, BookingStatus::Refunded, BookingStatus::RefundDue], true)) {
            // Paid again after it was already settled — a person needs to look.
            $booking->setFlag('Extra payment received · check it', 'warn')->save();
            $this->messenger->toOperator("{$booking->reference}: payment {$payment->reference} arrived after the booking was closed.");

            return;
        }

        $booking->update(['status' => BookingStatus::Paid]);
        $this->messenger->say($booking->client, $booking, 'payment_received', ['amount' => Money::format($payment->amount)]);

        $this->checkFareAndProceed($booking);
    }

    /** Re-price now and issue, hold or send to review according to the rules. */
    public function checkFareAndProceed(Booking $booking): void
    {
        if (ManualFlightSearch::enabled()) {
            // No fare to re-check: the operator books the seat and records the PNR.
            $booking->setFlag('Paid · book the ticket', 'ok')->save();
            $booking->event('hold', 'Paid · ready to ticket', 'Book with your agent, then record the PNR');
            $this->messenger->toOperator("{$booking->reference} ({$booking->client->displayName()}) has paid. Book the ticket: ".route('bookings.show', $booking));

            return;
        }

        try {
            $offers = $this->offers->search($booking, null, 'paid');
        } catch (Throwable $e) {
            Log::error("Post-payment search failed for {$booking->reference}: ".$e->getMessage());
            $this->review($booking, 'Flight search failed after payment');

            return;
        }

        if ($offers->isEmpty()) {
            $this->review($booking, 'No seats found after payment');

            return;
        }

        $cheapest = $offers->first();
        $booking->offers()->update(['selected' => false]);
        $cheapest->update(['selected' => true]);
        $booking->event('search', 'Fares checked after payment', $offers->count().' offers · cheapest '.Money::format($cheapest->amount));

        $decision = FareDecision::decide(
            paid: (int) $booking->paid_amount,
            liveFare: $cheapest->amount,
            autoIssue: (bool) $this->settings->get('auto_issue'),
            absorb: (bool) $this->settings->get('absorb'),
            absorbLimit: (int) $this->settings->get('absorb_limit', 0),
            paused: $this->settings->nightPauseActive(),
        );

        match ($decision->action) {
            FareDecision::ISSUE => $this->issue($booking, $cheapest),
            FareDecision::HOLD => $this->hold($booking, $decision),
            FareDecision::REVIEW => $this->review($booking, 'Fare up '.Money::format($decision->difference), $decision->difference),
        };
    }

    private function hold(Booking $booking, FareDecision $decision): void
    {
        $booking->stateSet('auto_pending', $this->settings->nightPauseActive())->save();
        $booking->setFlag($decision->absorbed ? 'Fare up '.Money::format($decision->difference).' · absorbable · ready' : 'Fare within quote · ready', 'ok')->save();
        $booking->event('hold', 'Ready to ticket', $decision->reason);
    }

    public function review(Booking $booking, string $why, ?int $difference = null): void
    {
        $booking->update(['status' => BookingStatus::FareReview]);
        $booking->stateSet('review_difference', $difference)->save();
        $booking->setFlag($why.' · review', 'warn')->save();
        $booking->event('review', 'Needs a fare review', $why);

        if ($this->settings->get('alert_operator')) {
            $this->messenger->toOperator("{$booking->reference} ({$booking->client->displayName()}): $why. ".route('bookings.review', $booking));
        }
    }

    /**
     * Book the offer with the provider, then send the e-ticket.
     *
     * @param  bool  $force  issue even if the refreshed price no longer fits (operator chose to absorb)
     */
    public function issue(Booking $booking, Offer $offer, bool $force = false): bool
    {
        $booking->loadMissing('client');

        try {
            $fresh = $this->offers->refresh($offer);
            if (! $fresh) {
                // The offer expired. Search again and try the cheapest from the same airline, else the cheapest.
                $newOffers = $this->offers->search($booking, $offer->depart_on, 'reissue');
                $replacement = $newOffers->firstWhere('airline', $offer->airline) ?? $newOffers->first();
                if (! $replacement) {
                    $this->review($booking, 'Offer expired and no seats found');

                    return false;
                }
                $offer = $replacement;
                $fresh = $this->offers->toData($offer);
            }
            $offer->refresh();

            $limit = (int) $booking->paid_amount + ($this->settings->get('absorb') ? (int) $this->settings->get('absorb_limit', 0) : 0);
            if (! $force && $offer->amount > $limit) {
                $booking->offers()->update(['selected' => false]);
                $offer->update(['selected' => true]);
                $this->review($booking, 'Fare up '.Money::format($offer->amount - (int) $booking->paid_amount), $offer->amount - (int) $booking->paid_amount);

                return false;
            }

            $passengers = $booking->passports()->get()->map(fn ($p) => $this->passports->toPassenger($p))->all();
            $order = $this->flights->createOrder(
                $fresh,
                $passengers,
                config('safara.duffel.contact_email') ?: 'bookings@example.com',
                $booking->client->contact_phone ?: $booking->client->phone,
            );
        } catch (Throwable $e) {
            Log::error("Ticketing failed for {$booking->reference}: ".$e->getMessage());
            $this->review($booking, 'Ticketing failed: '.mb_substr($e->getMessage(), 0, 80));

            return false;
        }

        $booking->offers()->update(['selected' => false]);
        $offer->update(['selected' => true]);

        $booking->fill([
            'status' => BookingStatus::Ticketed,
            'pnr' => $order->bookingReference,
            'provider_order_id' => $order->orderId,
            'ticketed_fare' => $offer->amount,
            'ticketed_at' => now(),
            'depart_on' => $offer->depart_on,
        ])->stateSet('ticket_numbers', $order->ticketNumbers)
            ->stateSet('segments', $order->segments ?: $offer->segments)
            ->stateSet('auto_pending', false)
            ->setFlag(null)->save();

        $booking->event('ticketed', 'Ticket issued · '.$order->bookingReference, $offer->airline.' · '.Money::format($offer->amount));

        $this->deliverTicket($booking->fresh(['client']), $offer);

        return true;
    }

    /**
     * Record a ticket the operator booked outside Safara and send it to the client.
     *
     * @param  string[]  $ticketNumbers
     * @param  ?string  $uploadedPath  the airline's own e-ticket PDF, sent instead of Safara's receipt
     */
    public function issueManually(Booking $booking, string $pnr, array $ticketNumbers = [], ?string $uploadedPath = null): void
    {
        $booking->loadMissing('client');
        $offer = $booking->selectedOffer() ?? $booking->offers()->latest('id')->firstOrFail();

        $booking->fill([
            'status' => BookingStatus::Ticketed,
            'pnr' => strtoupper($pnr),
            'ticketed_fare' => $offer->amount,
            'ticketed_at' => now(),
        ])->stateSet('ticket_numbers', $ticketNumbers)
            ->stateSet('segments', $offer->segments ?? [])
            ->stateSet('auto_pending', false)
            ->setFlag(null)->save();

        $booking->event('ticketed', 'Ticket recorded · '.strtoupper($pnr), $offer->airline.' · '.Money::format($offer->amount));

        $this->deliverTicket($booking->fresh(['client']), $offer, $uploadedPath);
    }

    public function deliverTicket(Booking $booking, Offer $offer, ?string $uploadedPath = null): void
    {
        $client = $booking->client;
        $path = $uploadedPath ?? $this->pdf->generate($booking, $offer);
        $booking->update(['ticket_path' => $path]);

        $name = $booking->passports()->first()?->firstName() ?: '';
        $this->messenger->say($client, $booking, 'ticket_issued', ['name' => $name, 'pnr' => $booking->pnr, 'airline' => $offer->airline]);
        $this->messenger->document($client, $booking, $path, 'E-ticket_'.strtoupper($booking->passports()->first()?->surname ?? 'SAFARA').'_'.$booking->pnr.'.pdf');
        $this->messenger->say($client, $booking, 'help_footer');

        $this->passports->scheduleDeletion($booking);

        $needsConsent = $booking->passports()->whereNull('consent_at')->exists();
        if ($needsConsent && $this->settings->get('retention') === 'expiry') {
            $this->messenger->say($client, $booking, 'save_passport_ask', [], ['save:yes', 'save:no']);
        }
    }

    // ---- operator decisions on a fare review -------------------------------

    public function letClientChoose(Booking $booking): void
    {
        $selected = $booking->selectedOffer();
        $diff = max(0, ($selected?->amount ?? 0) - (int) $booking->paid_amount);
        $alt = $this->offers->alternativeWithin($booking, (int) $booking->paid_amount);

        $vars = [
            'name' => $booking->passports()->first()?->firstName() ?: '',
            'diff' => Money::format($diff),
            'date' => $booking->depart_on->format('D j M'),
            'alt_date' => $alt?->depart_on->format('D j M') ?? '',
        ];
        // Template buttons: [0] pay more, [1] fly another date, [2] refund.
        $titles = $this->messenger->buttonTitles('fare_changed', $vars, $booking->client->language ?: 'en');
        $buttons = ['fare:diff' => $titles[0] ?? 'Pay '.Money::format($diff).' more'];
        if ($alt) {
            $buttons['fare:alt:'.$alt->id] = $titles[1] ?? 'Fly '.$vars['alt_date'];
        }
        $buttons['fare:refund'] = $titles[2] ?? 'Full refund';

        $booking->update(['status' => BookingStatus::AwaitingChoice]);
        $booking->stateSet('review_difference', $diff)->stateSet('alt_offer_id', $alt?->id)->setFlag('Waiting on client choice', 'warn')->save();
        $booking->event('review', 'Options sent to client', $alt ? 'Pay more, move to '.$alt->depart_on->format('D j M').', or refund' : 'Pay more or refund');

        $this->messenger->sayWithButtons($booking->client, $booking, 'fare_changed', $vars, $buttons);
    }

    public function absorb(Booking $booking): bool
    {
        $offer = $booking->selectedOffer() ?? $booking->latestOffers()->first();
        if (! $offer) {
            return false;
        }
        $booking->event('review', 'Operator absorbed the difference', Money::format(max(0, $offer->amount - (int) $booking->paid_amount)));

        return $this->issue($booking, $offer, force: true);
    }

    public function askDifference(Booking $booking): Payment
    {
        $offer = $booking->selectedOffer();
        $diff = max(0, ($offer?->amount ?? 0) - (int) $booking->paid_amount);
        $payment = $this->payments->openLink($booking, $diff, 'difference', now()->addMinutes($this->settings->holdMinutes()));

        $booking->update(['status' => BookingStatus::AwaitingDifference]);
        $booking->setFlag('Waiting for '.Money::format($diff), 'warn')->save();
        $booking->event('review', 'Difference requested · '.Money::format($diff), 'Pay link sent');

        $this->messenger->cta($booking->client, $booking, 'fare_pay_diff', [
            'name' => $booking->passports()->first()?->firstName() ?: '',
            'diff' => Money::format($diff),
            'date' => $booking->depart_on->format('D j M'),
        ], $payment->url());

        return $payment;
    }

    public function moveToAlternative(Booking $booking, Offer $alt): bool
    {
        $shift = (int) round($booking->depart_on->diffInDays($alt->depart_on, false));
        $booking->update([
            'depart_on' => $alt->depart_on,
            'return_on' => $booking->return_on?->copy()->addDays((int) $shift),
        ]);
        $booking->event('review', 'Moved to '.$alt->depart_on->format('D j M'), $alt->airline.' · '.Money::format($alt->amount));

        $this->messenger->say($booking->client, $booking, 'fare_alt_done', [
            'date' => $alt->depart_on->format('D j M'),
            'travellers' => $booking->travellersLabel(),
            'route' => $booking->routeNames(),
        ]);

        return $this->issue($booking, $alt);
    }

    public function refundFull(Booking $booking, string $reason = 'Could not book at the price paid'): Payment
    {
        $refund = $this->payments->refund($booking, (int) $booking->paid_amount, $reason);

        $this->messenger->say($booking->client, $booking, 'refund_sent', [
            'name' => $booking->passports()->first()?->firstName() ?: '',
            'amount' => Money::format($refund->amount),
            'refund_time' => $this->settings->get('refund_time', 'a few working days'),
        ]);

        return $refund;
    }
}
