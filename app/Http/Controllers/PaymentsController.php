<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\QuoteService;
use Illuminate\Http\Request;

class PaymentsController extends Controller
{
    private const TABS = ['today' => 'Today', 'open' => 'Open links', 'refunds' => 'Refunds', 'all' => 'All'];

    public function index(Request $request)
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'today';

        $q = Payment::query()->with('booking.client')->latest('id');
        match ($tab) {
            'today' => $q->where(fn ($w) => $w->whereDate('created_at', today())->orWhereDate('paid_at', today())->orWhere('status', 'open')->orWhere('status', 'due')),
            'open' => $q->where('status', 'open'),
            'refunds' => $q->where('kind', 'refund'),
            default => null,
        };

        $open = Payment::query()->where('status', 'open')->where('expires_at', '>', now());

        return view('payments.index', [
            'payments' => $q->paginate(15)->withQueryString(),
            'tab' => $tab,
            'tabs' => self::TABS,
            'collected' => Payment::query()->where('kind', 'charge')->where('status', 'confirmed')->whereDate('paid_at', today())->sum('amount'),
            'collectedCount' => Payment::query()->where('kind', 'charge')->where('status', 'confirmed')->whereDate('paid_at', today())->count(),
            'openSum' => (clone $open)->sum('amount'),
            'openCount' => (clone $open)->count(),
            'nextExpiry' => (clone $open)->min('expires_at'),
            'dueSum' => Payment::query()->where('status', 'due')->sum('amount'),
            'dueCount' => Payment::query()->where('status', 'due')->count(),
        ]);
    }

    public function refund(Payment $payment, PaymentService $payments)
    {
        abort_unless($payment->kind === 'refund' && $payment->status === 'due', 409);

        $ok = $payments->retryRefund($payment);

        return back()->with($ok ? 'status' : 'error', $ok ? 'Refund sent.' : 'The provider refused the refund. Refund by hand, then mark it sent.');
    }

    public function markSent(Payment $payment)
    {
        abort_unless($payment->kind === 'refund' && $payment->status === 'due', 409);
        $payment->update(['status' => 'sent', 'paid_at' => now(), 'meta' => array_merge($payment->meta ?? [], ['by_hand' => true])]);
        $payment->booking->update(['status' => \App\Enums\BookingStatus::Refunded]);
        $payment->booking->setFlag(null)->save();
        $payment->booking->event('refund', 'Refund marked as sent by hand', $payment->amountLabel());

        return back()->with('status', 'Marked as refunded.');
    }

    public function resend(Payment $payment, QuoteService $quotes)
    {
        abort_unless($payment->isOpen(), 409, 'This link has expired.');
        $quotes->remind($payment->booking, $payment);

        return back()->with('status', 'Pay link sent again on WhatsApp.');
    }
}
