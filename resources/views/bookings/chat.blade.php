@extends('layouts.desk')
@php($section = 'bookings')
@section('title', 'Chat · '.$booking->reference)
@section('content')
<header class="row-end">
    <div class="grow stack-s" style="gap:6px">
        <div class="crumbs"><a href="{{ route('bookings.index') }}">Bookings</a><span aria-hidden="true">/</span><a class="mono" href="{{ route('bookings.show', $booking) }}">{{ $booking->reference }}</a><span aria-hidden="true">/</span><span>Chat</span></div>
        <div class="row" style="gap:14px"><h1>{{ $booking->client->displayName() }}</h1>
            <span class="pill pill-l t-{{ $booking->status->tone() }}">{{ $booking->status->label() }}</span></div>
        <span class="small" style="color:var(--ink-2)">{{ $booking->client->phoneLabel() }} · Chats in {{ $booking->client->language === 'ha' ? 'Hausa' : 'English' }} ·
            {{ $booking->client->inServiceWindow() ? 'Inside the 24-hour window' : 'Outside the 24-hour window — only approved templates will reach them' }}</span>
    </div>
    <form method="post" action="{{ route('bookings.bot', $booking) }}">@csrf
        <button class="btn {{ $booking->bot_paused ? 'btn-primary' : '' }}" type="submit">@include('partials.icon', ['n' => $booking->bot_paused ? 'play' : 'pause', 'small' => true]){{ $booking->bot_paused ? 'Resume bot' : 'Take over chat' }}</button></form>
</header>

<div class="card" style="max-width:760px;padding:0;overflow:hidden">
    <div class="chat" style="border-radius:0;min-height:420px">
        @forelse ($messages as $m)
            @if ($loop->first || ! $m->created_at->isSameDay($messages[$loop->index - 1]->created_at))
                <span class="day">{{ $m->created_at->isToday() ? 'Today' : $m->created_at->format('D j M') }}</span>
            @endif
            @include('partials.bubble', ['m' => $m])
        @empty
            <span class="muted small" style="align-self:center;padding:40px">No messages yet.</span>
        @endforelse
    </div>
    <form method="post" action="{{ route('bookings.reply', $booking) }}" class="composer" style="padding:12px 14px;background:var(--chat);border-top:1px solid var(--line)">
        @csrf
        <label class="sr-only" for="body">Reply as {{ auth()->user()->name }}</label>
        <input class="input grow" id="body" name="body" placeholder="{{ $booking->bot_paused ? 'Reply as '.auth()->user()->name : 'Reply by hand (the bot keeps running — take over to pause it)' }}" required autocomplete="off">
        <button class="btn btn-primary" type="submit" style="border-radius:23px;height:46px">Send</button>
    </form>
</div>
@endsection

@push('scripts')
<script>window.scrollTo(0, document.body.scrollHeight);</script>
@endpush
