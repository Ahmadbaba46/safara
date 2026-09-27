@extends('layouts.desk')
@php($section = 'clients')
@section('title', 'Clients')
@section('content')
<div style="display:grid;grid-template-columns:340px minmax(0,1fr);gap:28px;align-items:start">
    <section class="stack" aria-label="Client list" style="gap:14px">
        <div class="row" style="align-items:baseline;gap:10px"><h1>Clients</h1><span class="muted">{{ $clients->total() }}</span></div>
        <form class="search" method="get" role="search" style="width:100%">
            @include('partials.icon', ['n' => 'search', 'small' => true])
            <label class="sr-only" for="cq">Search clients</label>
            <input id="cq" type="search" name="q" value="{{ $term }}" placeholder="Name or phone">
        </form>
        <div class="stack-s" style="gap:4px">
            @forelse ($clients as $c)
                <a href="{{ route('clients.index', array_filter(['c' => $c->id, 'q' => $term, 'page' => request('page')])) }}"
                   @if($current && $current->id === $c->id) aria-current="page" @endif
                   class="row" style="gap:12px;padding:10px 12px;border-radius:12px;text-decoration:none;color:var(--ink);border:1px solid {{ $current && $current->id === $c->id ? '#CFC8B8' : 'transparent' }};background:{{ $current && $current->id === $c->id ? '#FFF' : 'transparent' }}">
                    <span class="avatar" style="background:var(--green-soft);color:var(--green-ink);width:38px;height:38px">{{ $c->initials() }}</span>
                    <span class="grow stack-s" style="gap:0"><span class="strong">{{ $c->displayName() }}</span><span class="tiny muted">{{ $c->maskedPhone() }}</span></span>
                    <span class="stack-s right" style="gap:0"><span class="small strong">{{ $c->trips_count }} {{ $c->trips_count === 1 ? 'trip' : 'trips' }}</span></span>
                </a>
            @empty
                <span class="muted small">No clients yet. Anyone who messages the WhatsApp number appears here.</span>
            @endforelse
        </div>
        {{ $clients->links() }}
    </section>

    @if ($current)
        <section class="stack" aria-label="Client profile" style="gap:20px">
            <header class="row" style="gap:18px">
                <span class="avatar" style="width:64px;height:64px;background:var(--dark);color:var(--mint);font-family:var(--display);font-size:24px;font-weight:700">{{ $current->initials() }}</span>
                <div class="grow stack-s" style="gap:4px">
                    <h2 style="font-size:28px">{{ $current->displayName() }}</h2>
                    <span class="small" style="color:var(--ink-2)">+{{ $current->phone }} · Client since {{ $current->created_at->format('F Y') }} · Chats in {{ $current->language === 'ha' ? 'Hausa' : 'English' }}</span>
                </div>
                <form method="post" action="{{ route('clients.language', $current) }}">@csrf @method('put')
                    <input type="hidden" name="language" value="{{ $current->language === 'ha' ? 'en' : 'ha' }}">
                    <button class="btn" type="submit">Switch to {{ $current->language === 'ha' ? 'English' : 'Hausa' }}</button></form>
                <a class="btn btn-primary" href="{{ route('bookings.create') }}?phone={{ $current->phone }}">New quote</a>
            </header>

            <div class="tiles tiles-3">
                <div class="tile"><span class="tile-label">Trips booked</span><span class="tile-value" style="font-size:28px">{{ $trips->where('status', \App\Enums\BookingStatus::Ticketed)->count() }}</span></div>
                <div class="tile"><span class="tile-label">Total paid</span><span class="tile-value" style="font-size:28px">{{ \App\Support\Money::format($totalPaid) }}</span></div>
                <div class="tile"><span class="tile-label">Next trip</span><span class="tile-value" style="font-size:28px">{{ $next?->depart_on->format('D j M') ?? '—' }}</span></div>
            </div>

            <div class="grid-2" style="grid-template-columns:1.3fr 1fr">
                <section class="card" aria-labelledby="trips" style="gap:4px">
                    <h3 id="trips" style="margin-bottom:8px">Bookings</h3>
                    @forelse ($trips as $b)
                        <a href="{{ route('bookings.show', $b) }}" class="row" style="gap:14px;padding:12px 0;border-bottom:1px solid var(--line-2);text-decoration:none;color:var(--ink)">
                            <span class="mono small muted" style="width:72px">{{ $b->reference }}</span>
                            <span class="grow stack-s" style="gap:0"><span class="mono strong">{{ $b->routeCodes() }}</span><span class="tiny muted">{{ $b->depart_on?->format('D j M Y') ?? 'Date not set' }}</span></span>
                            <span class="pill t-{{ $b->status->tone() }}">{{ $b->status === \App\Enums\BookingStatus::Ticketed && $b->depart_on?->isPast() ? 'Flown' : $b->status->label() }}</span>
                            <span class="strong right" style="width:100px">{{ $b->amountLabel() }}</span>
                        </a>
                    @empty
                        <span class="muted small">No bookings yet.</span>
                    @endforelse
                    <form method="post" action="{{ route('clients.notes', $current) }}" class="field" style="margin-top:16px">
                        @csrf @method('put')
                        <label for="notes">Notes for the team</label>
                        <textarea class="textarea" id="notes" name="notes" rows="3" placeholder="Seat preferences, who they travel with, anything useful next time">{{ $current->notes }}</textarea>
                        <button class="btn btn-s" type="submit" style="align-self:flex-start">Save notes</button>
                    </form>
                </section>

                <section class="card" aria-labelledby="pp">
                    <div class="row"><h3 id="pp" class="grow">Passports on file</h3>
                        <span class="row tiny strong" style="gap:6px;color:var(--green-ink)">@include('partials.icon', ['n' => 'lock', 'small' => true])Encrypted</span></div>
                    @forelse ($passports as $p)
                        <dl class="kv">
                            <dt>Name</dt><dd>{{ strtoupper($p->surname) }}, {{ $p->given_names }}</dd>
                            <dt>Passport no.</dt><dd>{{ $p->maskedNumber() }}</dd>
                            <dt>Nationality</dt><dd>{{ $p->nationality }}</dd>
                            <dt>Date of birth</dt><dd>{{ $p->dob()?->format('d M Y') }}</dd>
                            <dt>Expiry</dt><dd>{{ $p->expiry?->format('d M Y') }}</dd>
                        </dl>
                        <div class="note">
                            @if ($p->consent_at)
                                Kept to prefill their next booking. They agreed in chat on {{ $p->consent_at->format('j M') }} at {{ $p->consent_at->format('H:i') }}.
                            @else
                                Not saved for next time.
                            @endif
                            @if ($p->delete_after) Deleted automatically on {{ $p->delete_after->format('j M Y') }}. @endif
                        </div>
                        <div class="row">
                            @if ($p->hasPhoto())<a class="btn btn-s grow" href="{{ route('clients.photo', [$current, $p]) }}" target="_blank" rel="noopener">View original photo</a>@endif
                            <form method="post" action="{{ route('clients.passport.destroy', [$current, $p]) }}" class="grow" onsubmit="return confirm('Delete this passport and its photo? This cannot be undone.')">
                                @csrf @method('delete')<button class="btn btn-s btn-danger btn-block" type="submit">Delete passport data</button></form>
                        </div>
                        @if (! $loop->last)<hr style="border:0;border-top:1px solid var(--line);margin:4px 0">@endif
                    @empty
                        <span class="muted small">No passport on file.</span>
                    @endforelse
                </section>
            </div>
        </section>
    @endif
</div>
@endsection
