<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\BookingStatus;
use App\Jobs\AfterPayment;
use App\Models\Booking;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentService
{
    public function __construct(private PaymentGateway $gateway) {}

    /** Close any open link for this booking and open a new one. */
    public function openLink(Booking $booking, int $amount, string $purpose, ?Carbon $expiresAt): Payment
    {
        $booking->payments()->where('kind', 'charge')->where('status', 'open')->update(['status' => 'expired']);

        return $booking->payments()->create([
            'kind' => 'charge',
            'purpose' => $purpose,
            'provider' => $this->gateway->name(),
            'amount' => $amount,
            'status' => 'open',
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Record a successful payment. Safe to call more than once for the same
     * payment (webhook + return page); only the first call does anything.
     */
    public function markPaid(Payment $payment, ?string $providerRef = null, ?string $method = null): bool
    {
        $first = DB::transaction(function () use ($payment, $providerRef, $method) {
            $fresh = Payment::query()->lockForUpdate()->find($payment->id);
            if (! $fresh || $fresh->status === 'confirmed') {
                return false;
            }
            $fresh->update([
                'status' => 'confirmed',
                'paid_at' => now(),
                'provider_ref' => $providerRef ?? $fresh->provider_ref,
                'method' => $method ?? $fresh->method,
            ]);
            $booking = $fresh->booking;
            $booking->increment('paid_amount', $fresh->amount);
            $booking->event('payment', 'Payment confirmed · '.Money::format($fresh->amount), 'ref '.$fresh->reference);
            $payment->refresh();

            return true;
        });

        if ($first) {
            AfterPayment::dispatch($payment->id);
        }

        return $first;
    }

    /** Price holds that ran out without payment. */
    public function expireHolds(): int
    {
        $count = 0;
        Payment::query()->where('status', 'open')->whereNotNull('expires_at')->where('expires_at', '<', now())
            ->with('booking')->each(function (Payment $p) use (&$count) {
                $p->update(['status' => 'expired']);
                $b = $p->booking;
                if ($b->status === BookingStatus::AwaitingPayment && $p->purpose === 'fare') {
                    $b->update(['status' => BookingStatus::Expired]);
                    $b->event('expired', 'Price hold ended', 'No payment by '.$p->expires_at->timezone(config('safara.timezone'))->format('H:i'));
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Refund what the client paid. Marks the refund "due" if the provider
     * refuses, so it shows on the Payments screen for a person to finish.
     */
    public function refund(Booking $booking, int $amount, string $reason): Payment
    {
        $charge = $booking->payments()->where('kind', 'charge')->where('status', 'confirmed')->orderByDesc('amount')->first();

        $refund = $booking->payments()->create([
            'kind' => 'refund',
            'purpose' => 'refund',
            'provider' => $this->gateway->name(),
            'amount' => $amount,
            'status' => 'due',
            'meta' => ['reason' => $reason, 'charge' => $charge?->reference],
        ]);

        if ($charge) {
            try {
                $result = $this->gateway->refund($charge, $amount);
                if ($result->ok) {
                    $refund->update(['status' => 'sent', 'provider_ref' => $result->providerRef, 'paid_at' => now()]);
                }
            } catch (Throwable $e) {
                Log::error("Refund failed for {$booking->reference}: ".$e->getMessage());
            }
        }

        $sent = $refund->status === 'sent';
        $booking->update(['status' => $sent ? BookingStatus::Refunded : BookingStatus::RefundDue]);
        $booking->setFlag($sent ? null : 'Refund due · finish it on Payments', 'warn')->save();
        $booking->event('refund', ($sent ? 'Refund sent · ' : 'Refund due · ').Money::format($amount), $reason);

        return $refund;
    }

    /** Retry a refund marked "due" (from the Payments screen). */
    public function retryRefund(Payment $refund): bool
    {
        $booking = $refund->booking;
        $charge = $booking->payments()->where('kind', 'charge')->where('status', 'confirmed')->orderByDesc('amount')->first();
        if (! $charge) {
            return false;
        }
        $result = $this->gateway->refund($charge, $refund->amount);
        if (! $result->ok) {
            return false;
        }
        $refund->update(['status' => 'sent', 'provider_ref' => $result->providerRef, 'paid_at' => now()]);
        $booking->update(['status' => BookingStatus::Refunded]);
        $booking->setFlag(null)->save();
        $booking->event('refund', 'Refund sent · '.Money::format($refund->amount), 'from the Payments screen');

        return true;
    }
}
