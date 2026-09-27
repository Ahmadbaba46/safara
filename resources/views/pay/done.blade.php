@extends('layouts.phone')
@section('title', 'Payment confirmed')
@section('content')
@php
    $ticketed = $booking->status === \App\Enums\BookingStatus::Ticketed;
    $waNumber = config('safara.meta.business_number');
@endphp
<div class="stack-s" style="align-items:center;gap:14px;padding-top:24px;text-align:center">
    <span class="check-big">@include('partials.icon', ['n' => 'check'])</span>
    <h1 class="p-title">Payment confirmed</h1>
    <span class="muted" style="font-size:15px;line-height:1.5;max-width:300px">{{ $payment->amountLabel() }} received. {{ $ticketed ? 'Your e-ticket has been sent to your WhatsApp chat.' : 'Your e-ticket will arrive in your WhatsApp chat in a few minutes.' }}</span>
</div>

<ol class="steps" aria-label="Progress">
    <li><span class="step-dot done">@include('partials.icon', ['n' => 'check', 'small' => true])</span><span class="grow strong" style="font-size:15px">Payment received</span><span class="tiny muted">{{ $payment->paid_at?->format('H:i') }}</span></li>
    <li><span class="step-dot {{ $ticketed ? 'done' : 'now' }}">@if($ticketed)@include('partials.icon', ['n' => 'check', 'small' => true])@endif</span><span class="grow strong" style="font-size:15px">Booking your seat</span>@unless($ticketed)<span class="tiny strong" style="color:var(--green)">Now</span>@endunless</li>
    <li><span class="step-dot {{ $ticketed ? 'done' : '' }}">@if($ticketed)@include('partials.icon', ['n' => 'check', 'small' => true])@endif</span><span class="grow" style="font-size:15px;{{ $ticketed ? 'font-weight:600' : 'color:var(--muted)' }}">E-ticket sent on WhatsApp</span></li>
</ol>

<dl class="kv" style="padding:0 4px;grid-template-columns:110px 1fr">
    <dt>Trip</dt><dd style="font-family:var(--body);font-weight:400">{{ $booking->routeNames() }} · {{ $booking->depart_on?->format('D j M') }}</dd>
    <dt>{{ $booking->passports->count() > 1 ? 'Travellers' : 'Traveller' }}</dt><dd style="font-family:var(--body);font-weight:400">{{ $booking->passports->map->fullName()->implode(', ') }}</dd>
    <dt>Payment ref</dt><dd>{{ $payment->reference }}</dd>
</dl>

<div class="stack-s" style="margin-top:auto;gap:10px">
    <a class="btn btn-primary p-cta" href="{{ $waNumber ? 'https://wa.me/'.$waNumber : '#' }}">@include('partials.icon', ['n' => 'chat', 'small' => true])Back to the chat</a>
    <span class="tiny muted" style="text-align:center">Nothing arrived after 15 minutes? Reply HELP in the chat.</span>
</div>
@unless ($ticketed)
    @push('scripts')<script>setTimeout(() => location.reload(), 15000);</script>@endpush
@endunless
@endsection
