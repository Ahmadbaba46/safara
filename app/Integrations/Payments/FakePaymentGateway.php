<?php

namespace App\Integrations\Payments;

use App\Contracts\PaymentGateway;
use App\Integrations\Data\GatewayResult;
use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * Stands in for a real provider until you choose one. Checkout goes to a
 * Safara page with a "Simulate successful payment" button. Refunds succeed.
 * Refused in production so no one pays with it by mistake.
 */
class FakePaymentGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'test';
    }

    public function label(): string
    {
        return 'Test payments (no money moves)';
    }

    public function checkout(Payment $payment, string $method, string $returnUrl): string
    {
        abort_if(app()->environment('production'), 503, 'No payment provider is configured.');

        return route('pay.fake', ['token' => $payment->token, 'method' => $method]);
    }

    public function verify(Payment $payment): GatewayResult
    {
        return new GatewayResult($payment->status === 'confirmed', $payment->reference, $payment->provider_ref, $payment->method);
    }

    public function webhook(Request $request): ?GatewayResult
    {
        return null;
    }

    public function refund(Payment $charge, int $amount): GatewayResult
    {
        return new GatewayResult(true, $charge->reference, 'test_refund_'.$charge->id, null, $amount);
    }
}
