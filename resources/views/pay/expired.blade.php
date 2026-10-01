@extends('layouts.phone')
@section('title', 'Price hold ended')
@section('content')
<div class="stack-s" style="gap:12px;padding-top:24px">
    <span class="timer" style="background:var(--mute-soft);color:var(--ink-2)">@include('partials.icon', ['n' => 'clock', 'small' => true])Price hold ended</span>
    <h1 class="p-title">This price is no longer held</h1>
    <p class="muted" style="margin:0;line-height:1.5;font-size:15px">
        @if ($payment->status === 'expired' || $payment->expires_at?->isPast())
            Fares change, so we held {{ $payment->amountLabel() }} for you until {{ $payment->expires_at?->timezone(config('safara.timezone'))->format('H:i') }}.
            Tap below and we’ll check again and send a new price to your chat.
        @else
            This link isn’t open any more. Your chat has the latest details.
        @endif
    </p>
</div>
<div class="p-summary">
    <div class="row" style="gap:10px;font-family:var(--display);font-weight:700;font-size:22px">
        <span>{{ \App\Support\Iata::city($booking->origin) }}</span>@include('partials.icon', ['n' => 'arrow'])<span>{{ \App\Support\Iata::city($booking->destination) }}</span>
    </div>
    <div class="p-line"><span>Date</span><span>{{ $booking->depart_on?->format('D j M') }}</span></div>
    <div class="p-line"><span>Previous price</span><span style="text-decoration:line-through">{{ $payment->amountLabel() }}</span></div>
</div>
@if (in_array($booking->status, [\App\Enums\BookingStatus::Expired, \App\Enums\BookingStatus::AwaitingPayment], true))
    <form method="post" action="{{ route('pay.requote', $payment->token) }}" style="margin-top:auto">
        @csrf
        <button class="btn btn-primary p-cta btn-block" type="submit">Get a new price</button>
    </form>
@endif
@endsection
