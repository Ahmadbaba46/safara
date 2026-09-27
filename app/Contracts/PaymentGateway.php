<?php

namespace App\Contracts;

use App\Integrations\Data\GatewayResult;
use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * Plug in any provider (Paystack, Flutterwave, Monnify, Remita...) by
 * implementing this and setting SAFARA_PAYMENTS_DRIVER to your class name.
 *
 * The client always lands on Safara's own pay page first (summary + method),
 * then checkout() sends them to the provider, which brings them back to
 * $returnUrl. The webhook is the source of truth; returns are a fast path.
 */
interface PaymentGateway
{
    /** Short name stored on each payment, e.g. "paystack". */
    public function name(): string;

    /** Customer-facing name shown on the pay page. */
    public function label(): string;

    /**
     * Start a checkout and return the URL to send the client to.
     *
     * @param  string  $method  card | transfer | ussd
     */
    public function checkout(Payment $payment, string $method, string $returnUrl): string;

    /** Ask the provider whether this payment went through. */
    public function verify(Payment $payment): GatewayResult;

    /**
     * Turn a provider webhook into a result, or null if it isn't a payment
     * event we care about. MUST verify the provider's signature.
     */
    public function webhook(Request $request): ?GatewayResult;

    /** Refund all or part of a confirmed charge. */
    public function refund(Payment $charge, int $amount): GatewayResult;
}
