<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentGateway;
use App\Enums\BookingStatus;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\QuoteService;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** The client-facing pay link pages (opened from WhatsApp on a phone). */
class PayController extends Controller
{
    public function __construct(private PaymentGateway $gateway, private PaymentService $payments) {}

    private function find(string $token): Payment
    {
        return Payment::query()->where('token', $token)->where('kind', 'charge')->with('booking.client', 'booking.passports')->firstOrFail();
    }

    public function show(string $token, Settings $settings)
    {
        $payment = $this->find($token);

        if ($payment->status === 'confirmed') {
            return redirect()->route('pay.done', $token);
        }
        if (! $payment->isOpen()) {
            return view('pay.expired', ['payment' => $payment, 'booking' => $payment->booking]);
        }

        $methods = array_values(array_intersect(['card', 'transfer', 'ussd'], (array) $settings->get('payment_methods', ['card'])));

        return view('pay.show', [
            'payment' => $payment,
            'booking' => $payment->booking,
            'methods' => $methods ?: ['card'],
            'gateway' => $this->gateway,
        ]);
    }

    public function start(Request $request, string $token, Settings $settings)
    {
        $payment = $this->find($token);
        abort_unless($payment->isOpen(), 410);
        $method = $request->validate(['method' => 'required|in:card,transfer,ussd'])['method'];
        abort_unless(in_array($method, (array) $settings->get('payment_methods', ['card']), true), 422);

        $payment->update(['method' => $method]);

        return redirect()->away($this->gateway->checkout($payment, $method, route('pay.return', $token)));
    }

    /** Where the provider sends the client back to. */
    public function back(string $token)
    {
        $payment = $this->find($token);
        if ($payment->status !== 'confirmed') {
            $result = $this->gateway->verify($payment);
            if ($result->ok) {
                $this->payments->markPaid($payment, $result->providerRef, $result->method);
            }
        }

        if ($payment->fresh()->status === 'confirmed') {
            return redirect()->route('pay.done', $token);
        }

        return redirect()->route('pay.show', $token)->with('notice', "We haven't received your payment yet. If you were charged, it will show here in a few minutes.");
    }

    public function done(string $token)
    {
        $payment = $this->find($token);
        abort_unless($payment->status === 'confirmed', 404);

        return view('pay.done', ['payment' => $payment, 'booking' => $payment->booking]);
    }

    /** "Get a new price" on an expired link: re-quote on WhatsApp. */
    public function requote(string $token, QuoteService $quotes)
    {
        $payment = $this->find($token);
        $booking = $payment->booking;
        if ($booking->status === BookingStatus::AwaitingPayment && ! $payment->isOpen()) {
            $this->payments->expireHolds();
            $booking->refresh();
        }
        if ($booking->status === BookingStatus::Expired) {
            $quotes->requote($booking);
        }

        return view('pay.requoted', ['booking' => $booking]);
    }

    // ---- test gateway only ----------------------------------------------------

    public function fake(Request $request, string $token)
    {
        abort_unless(config('safara.drivers.payments') === 'fake' && (! app()->environment('production') || config('safara.demo')), 404);

        return view('pay.fake', ['payment' => $this->find($token), 'method' => $request->query('method', 'card')]);
    }

    public function fakeComplete(Request $request, string $token)
    {
        abort_unless(config('safara.drivers.payments') === 'fake' && (! app()->environment('production') || config('safara.demo')), 404);
        $payment = $this->find($token);
        if ($request->input('outcome') === 'success') {
            $this->payments->markPaid($payment, 'test_'.Str::lower(Str::random(10)), $request->input('method', 'card'));
        }

        return redirect()->route('pay.return', $token);
    }
}
