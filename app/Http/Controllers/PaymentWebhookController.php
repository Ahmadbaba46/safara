<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentGateway;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentGateway $gateway, PaymentService $payments)
    {
        $result = $gateway->webhook($request);
        if (! $result || ! $result->ok || ! $result->reference) {
            return response('ignored');
        }

        $payment = Payment::query()->where('reference', $result->reference)->where('kind', 'charge')->first();
        if (! $payment) {
            Log::warning('Payment webhook for unknown reference '.$result->reference);

            return response('unknown reference');
        }

        if ($result->amount !== null && $result->amount < $payment->amount) {
            Log::warning("Underpayment on {$payment->reference}: got {$result->amount}, expected {$payment->amount}");
            $payment->booking->setFlag('Underpaid · check payment', 'warn')->save();

            return response('amount mismatch');
        }

        $payments->markPaid($payment, $result->providerRef, $result->method);

        return response('ok');
    }
}
