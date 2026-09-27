@extends('layouts.phone')
@section('title', 'Pay for your flight')
@section('content')
@php
    $first = $booking->passports->first()?->firstName();
    $labels = ['card' => ['Card', 'Verve, Visa, Mastercard'], 'transfer' => ['Bank transfer', 'Pay to a one-time account number'], 'ussd' => ['USSD', 'Dial a code from your bank line']];
    $cta = ['card' => 'Pay '.$payment->amountLabel(), 'transfer' => 'Get account number', 'ussd' => 'Get USSD code'];
@endphp
<div class="stack-s" style="gap:10px">
    <h1 class="p-title">{{ $payment->purpose === 'difference' ? 'Pay the fare difference' : 'Pay for your flight' }}{{ $first ? ', '.$first : '' }}</h1>
    @if ($payment->expires_at)
        <span class="timer" data-expires="{{ $payment->expires_at->toIso8601String() }}">@include('partials.icon', ['n' => 'clock', 'small' => true])<span>Price held for <span data-left>{{ \App\Models\Payment::left($payment->expires_at) }}</span></span></span>
    @endif
</div>

<div class="p-summary">
    <div class="row" style="gap:10px;font-family:var(--display);font-weight:700;font-size:22px">
        <span>{{ \App\Support\Iata::city($booking->origin) }}</span>@include('partials.icon', ['n' => 'arrow'])<span>{{ \App\Support\Iata::city($booking->destination) }}</span>
    </div>
    <div class="p-line"><span>Date</span><span>{{ $booking->depart_on?->format('D j M') }} · {{ $booking->return_on ? 'return '.$booking->return_on->format('D j M') : 'one way' }}</span></div>
    <div class="p-line"><span>{{ $booking->passports->count() > 1 ? 'Travellers' : 'Traveller' }}</span><span class="right">{!! $booking->passports->map(fn ($p) => e($p->fullName()))->implode('<br>') ?: e($booking->travellersLabel()) !!}</span></div>
    @if ($o = $booking->selectedOffer())<div class="p-line"><span>Bag</span><span>{{ $o->baggage }}</span></div>@endif
    <div class="p-total"><span class="grow strong">Total</span><b>{{ $payment->amountLabel() }}</b></div>
</div>

<form method="post" action="{{ route('pay.start', $payment->token) }}" class="stack" style="gap:18px;flex-grow:1">
    @csrf
    <fieldset style="border:0;margin:0;padding:0;display:flex;flex-direction:column;gap:8px">
        <legend class="strong" style="padding:0;margin-bottom:8px">Pay with</legend>
        @foreach ($methods as $m)
            <label class="opt" style="min-height:56px">
                <input type="radio" name="method" value="{{ $m }}" @checked($loop->first) data-cta="{{ $cta[$m] }}">
                <span class="grow stack-s" style="gap:0"><span class="strong" style="font-size:15px">{{ $labels[$m][0] }}</span><span class="tiny muted">{{ $labels[$m][1] }}</span></span>
            </label>
        @endforeach
    </fieldset>
    <div class="stack-s" style="margin-top:auto;gap:12px">
        <button class="btn btn-primary p-cta" type="submit">@include('partials.icon', ['n' => 'lock', 'small' => true])<span id="cta">{{ $cta[$methods[0]] }}</span></button>
        <span class="tiny muted" style="text-align:center;line-height:1.5">Processed securely by {{ $gateway->label() }}. Your e-ticket arrives on WhatsApp once payment is confirmed.</span>
    </div>
</form>
@endsection

@push('scripts')
<script>
document.querySelectorAll('input[name=method]').forEach((r) => r.addEventListener('change', () => { document.getElementById('cta').textContent = r.dataset.cta; }));
const t = document.querySelector('[data-expires]');
if (t) {
    const end = new Date(t.dataset.expires).getTime();
    const out = t.querySelector('[data-left]');
    const tick = () => {
        const s = Math.max(0, Math.floor((end - Date.now()) / 1000));
        if (s === 0) { location.reload(); return; }
        const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
        out.textContent = `${h}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`;
    };
    tick(); setInterval(tick, 1000);
}
</script>
@endpush
