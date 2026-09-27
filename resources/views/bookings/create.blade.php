@extends('layouts.desk')
@php($section = 'desk')
@section('title', 'Manual quote')
@section('content')
<header class="stack-s" style="gap:6px">
    <div class="crumbs"><a href="{{ route('desk') }}">Desk</a><span aria-hidden="true">/</span><span>Manual quote</span></div>
    <h1>Manual quote</h1>
    <span class="muted">For someone who called or walked in. We’ll message them on WhatsApp for their passport photo, then the usual flow takes over.</span>
</header>

<form method="post" action="{{ route('bookings.store') }}" class="card" style="max-width:720px;gap:18px">
    @csrf
    <div class="grid-2" style="gap:14px">
        <label class="field">WhatsApp number<input class="input" name="phone" value="{{ old('phone', request('phone')) }}" placeholder="0803 000 0000" inputmode="tel" required></label>
        <label class="field">Name (optional)<input class="input" name="name" value="{{ old('name') }}"></label>
        <label class="field">From
            <select class="select" name="origin" required>
                <option value="">Choose</option>
                @foreach ($cities as $code => $city)<option value="{{ $code }}" @selected(old('origin') === $code)>{{ $city }} ({{ $code }})</option>@endforeach
            </select>
        </label>
        <label class="field">To
            <select class="select" name="destination" required>
                <option value="">Choose</option>
                @foreach ($cities as $code => $city)<option value="{{ $code }}" @selected(old('destination') === $code)>{{ $city }} ({{ $code }})</option>@endforeach
            </select>
        </label>
        <label class="field">Travel date<input class="input" type="date" name="depart_on" value="{{ old('depart_on') }}" min="{{ today()->toDateString() }}" required></label>
        <label class="field">Return date (optional)<input class="input" type="date" name="return_on" value="{{ old('return_on') }}" min="{{ today()->toDateString() }}"></label>
        <label class="field">Travellers<input class="input" type="number" name="travellers" value="{{ old('travellers', 1) }}" min="1" max="9" required></label>
        <label class="field">Chat language
            <select class="select" name="language"><option value="en">English</option><option value="ha" @selected(old('language') === 'ha')>Hausa</option></select>
        </label>
    </div>
    <div class="note">If they haven’t messaged you in the last 24 hours, WhatsApp only delivers an approved template. Set the “Manual quote start” template’s Meta name on Message templates once Meta approves it.</div>
    <div class="row"><span class="grow"></span><a class="btn" href="{{ route('desk') }}">Cancel</a><button class="btn btn-primary" type="submit">Send on WhatsApp</button></div>
</form>
@endsection
