@extends('layouts.phone')
@section('title', 'New price sent')
@section('content')
<div class="stack-s" style="align-items:center;gap:14px;padding-top:40px;text-align:center">
    <span class="check-big">@include('partials.icon', ['n' => 'chat'])</span>
    <h1 class="p-title">{{ $booking->client->isApp() ? 'Check your chat' : 'Check your WhatsApp' }}</h1>
    <span class="muted" style="font-size:15px;line-height:1.5;max-width:300px">We’ve checked fares again for {{ $booking->routeNames() }} and sent you a new price with a fresh pay link.</span>
</div>
@if ($booking->client->isApp())
    <a class="btn btn-primary p-cta" style="margin-top:auto" href="{{ route('app') }}">Back to the chat</a>
@elseif ($n = config('safara.meta.business_number'))
    <a class="btn btn-primary p-cta" style="margin-top:auto" href="https://wa.me/{{ $n }}">Open WhatsApp</a>
@endif
@endsection
