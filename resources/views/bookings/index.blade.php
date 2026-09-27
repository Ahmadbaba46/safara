@extends('layouts.desk')
@php($section = 'bookings')
@section('title', 'Bookings')
@section('content')
<header class="row-end">
    <div class="grow stack-s" style="gap:4px">
        <h1>Bookings</h1>
        <span class="muted">Every request from WhatsApp and every manual quote</span>
    </div>
    <form class="search" method="get" action="{{ route('bookings.index') }}" role="search">
        @include('partials.icon', ['n' => 'search', 'small' => true])
        <label class="sr-only" for="q">Search bookings</label>
        <input id="q" type="search" name="q" value="{{ request('q') }}" placeholder="Name, phone, booking or PNR">
        <input type="hidden" name="tab" value="{{ $tab }}">
    </form>
    <a class="btn" href="{{ route('bookings.export', request()->query()) }}">@include('partials.icon', ['n' => 'download', 'small' => true])Export CSV</a>
</header>

<div class="row" style="align-items:flex-end;gap:24px;border-bottom:1px solid var(--line)">
    <nav class="tabs grow" aria-label="Booking status" style="border-bottom:0">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('bookings.index', array_filter(['tab' => $key, 'q' => request('q'), 'route' => request('route')])) }}" aria-current="{{ $tab === $key ? 'true' : 'false' }}">{{ $label }}<span class="count">{{ $counts[$key] }}</span></a>
        @endforeach
    </nav>
    <form method="get" class="row" style="padding-bottom:6px;gap:8px">
        <input type="hidden" name="tab" value="{{ $tab }}">
        @if (request('q'))<input type="hidden" name="q" value="{{ request('q') }}">@endif
        <label class="sr-only" for="route">Route</label>
        <select id="route" name="route" class="select btn-s" style="height:36px;font-size:13px;width:auto" onchange="this.form.submit()">
            <option value="">Route: any</option>
            @foreach (['hajj' => 'Hajj & Umrah', 'domestic' => 'Domestic', 'international' => 'International'] as $k => $v)
                <option value="{{ $k }}" @selected(request('route') === $k)>{{ $v }}</option>
            @endforeach
        </select>
        <label class="sr-only" for="source">Source</label>
        <select id="source" name="source" class="select" style="height:36px;font-size:13px;width:auto" onchange="this.form.submit()">
            <option value="">Source: any</option>
            <option value="whatsapp" @selected(request('source') === 'whatsapp')>WhatsApp</option>
            <option value="manual" @selected(request('source') === 'manual')>Manual quote</option>
        </select>
    </form>
</div>

<div class="table">
    <table>
        <thead>
            <tr><th>Booking</th><th>Client</th><th>Route</th><th>Travel</th><th>Pax</th><th class="right">Amount</th><th>Status</th><th class="right">Updated</th></tr>
        </thead>
        <tbody>
        @forelse ($bookings as $b)
            <tr class="link-row" onclick="location.href='{{ route('bookings.show', $b) }}'">
                <td><a class="row-link mono small" href="{{ route('bookings.show', $b) }}">{{ $b->reference }}</a></td>
                <td><div class="strong">{{ $b->client->displayName() }}</div><div class="tiny muted">{{ $b->client->maskedPhone() }}</div></td>
                <td class="mono strong">{{ $b->routeCodes() }}</td>
                <td>{{ $b->dateLabel() }}</td>
                <td>{{ $b->travellers ?? '—' }}</td>
                <td class="right strong">{{ $b->amountLabel() }}</td>
                <td><span class="pill t-{{ $b->status->tone() }}">{{ $b->status->label() }}</span>@if($b->bot_paused) <span class="pill t-warn">Bot paused</span>@endif</td>
                <td class="right small muted">{{ $b->updated_at->isToday() ? $b->updated_at->format('H:i') : $b->updated_at->format('j M') }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="empty">No bookings match. New WhatsApp conversations appear here automatically.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
{{ $bookings->links() }}
@endsection
