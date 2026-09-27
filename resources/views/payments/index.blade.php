@extends('layouts.desk')
@php($section = 'payments')
@section('title', 'Payments')
@section('content')
<header class="row-end">
    <div class="grow stack-s" style="gap:4px">
        <h1>Payments</h1>
        <span class="muted">Pay links, confirmations and refunds · {{ app(\App\Contracts\PaymentGateway::class)->label() }}</span>
    </div>
</header>

<div class="tiles tiles-3">
    <div class="tile dark"><span class="tile-label">Collected today</span><span class="tile-value">{{ \App\Support\Money::format($collected) }}</span><span class="tile-foot">{{ $collectedCount }} {{ $collectedCount === 1 ? 'payment' : 'payments' }}</span></div>
    <div class="tile warn"><span class="tile-label">Open pay links</span><span class="tile-value">{{ \App\Support\Money::format($openSum) }}</span><span class="tile-foot">{{ $openCount }} {{ $openCount === 1 ? 'link' : 'links' }}{{ $nextExpiry ? ' · next expires in '.\App\Models\Payment::left(\Illuminate\Support\Carbon::parse($nextExpiry)) : '' }}</span></div>
    <div class="tile bad"><span class="tile-label">Refunds due</span><span class="tile-value">{{ \App\Support\Money::format($dueSum) }}</span><span class="tile-foot">{{ $dueCount ? $dueCount.' to finish' : 'Nothing waiting' }}</span></div>
</div>

<nav class="tabs" aria-label="Payment filter">
    @foreach ($tabs as $key => $label)
        <a href="{{ route('payments.index', ['tab' => $key]) }}" aria-current="{{ $tab === $key ? 'true' : 'false' }}">{{ $label }}</a>
    @endforeach
</nav>

<div class="table">
    <table>
        <thead><tr><th>Reference</th><th>Booking</th><th>Client</th><th>Method</th><th class="right">Amount</th><th>Status</th><th class="right">Action</th></tr></thead>
        <tbody>
        @forelse ($payments as $p)
            <tr>
                <td class="mono small muted">{{ $p->reference }}</td>
                <td><a class="mono small" href="{{ route('bookings.show', $p->booking) }}">{{ $p->booking->reference }}</a></td>
                <td><div class="strong">{{ $p->booking->client->displayName() }}</div>
                    <div class="tiny muted">{{ $p->kind === 'refund' ? 'Refund' : ($p->purpose === 'difference' ? 'Fare difference' : 'Fare') }} · {{ ($p->paid_at ?? $p->created_at)->isToday() ? ($p->paid_at ?? $p->created_at)->format('H:i') : ($p->paid_at ?? $p->created_at)->format('j M') }}</div></td>
                <td class="muted">{{ $p->method ? ucfirst($p->method) : '—' }}</td>
                <td class="right strong">{{ $p->kind === 'refund' ? '−' : '' }}{{ $p->amountLabel() }}</td>
                <td><span class="pill t-{{ $p->tone() }}">{{ $p->statusLabel() }}</span></td>
                <td class="right">
                    @if ($p->kind === 'refund' && $p->status === 'due')
                        <div class="row" style="justify-content:flex-end;gap:10px">
                            <form method="post" action="{{ route('payments.refund', $p) }}">@csrf<button class="link-btn small" type="submit">Retry refund</button></form>
                            <form method="post" action="{{ route('payments.sent', $p) }}" onsubmit="return confirm('Mark as refunded by hand?')">@csrf<button class="link-btn small" type="submit" style="color:var(--ink-2)">Mark sent</button></form>
                        </div>
                    @elseif ($p->isOpen())
                        <form method="post" action="{{ route('payments.resend', $p) }}">@csrf<button class="link-btn small" type="submit">Resend</button></form>
                    @elseif ($p->booking->status === \App\Enums\BookingStatus::FareReview)
                        <a class="small strong" style="text-decoration:none" href="{{ route('bookings.review', $p->booking) }}">Review</a>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty">No payments here yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
{{ $payments->links() }}
@endsection
