@extends('layouts.desk')
@php
    $section = 'bookings';
    $S = \App\Enums\BookingStatus::class;
    $canIssue = (int) $booking->paid_amount > 0 && ! $booking->ticketed_at && $offers->isNotEmpty() && ! in_array($booking->status, [$S::Refunded, $S::RefundDue, $S::Cancelled], true);
    $fareNow = $selected?->amount;
    $margin = $booking->quote_amount && $fareNow ? (int) ($booking->paid_amount ?: $booking->quote_amount) - $fareNow : null;
    $openLink = $booking->payments->first(fn ($p) => $p->kind === 'charge' && $p->isOpen());
@endphp
@section('title', $booking->reference)
@section('content')
<header class="row-end">
    <div class="grow stack-s" style="gap:6px">
        <div class="crumbs"><a href="{{ route('bookings.index') }}">Bookings</a><span aria-hidden="true">/</span><span class="mono">{{ $booking->reference }}</span></div>
        <div class="row" style="gap:14px">
            <h1>{{ $booking->client->displayName() }}</h1>
            <span class="pill pill-l t-{{ $booking->status->tone() }}">{{ $booking->status->label() }}</span>
            @if ($booking->bot_paused)<span class="pill pill-l t-warn">Bot paused</span>@endif
        </div>
        <span class="small" style="color:var(--ink-2)">{{ $booking->tripLine() }} · {{ $booking->client->maskedPhone() }}@if($booking->source === 'manual') · Manual quote @endif</span>
    </div>
    <a class="btn" href="{{ route('bookings.chat', $booking) }}">@include('partials.icon', ['n' => 'chat', 'small' => true])Open chat</a>
    @if ($booking->status === $S::FareReview || $booking->status === $S::AwaitingChoice)
        <a class="btn btn-primary" href="{{ route('bookings.review', $booking) }}">Review fare</a>
    @elseif ($canIssue)
        <button class="btn btn-primary" type="submit" form="issue-form">@include('partials.icon', ['n' => 'ticket', 'small' => true])Issue ticket</button>
    @elseif ($booking->ticket_path)
        <a class="btn btn-primary" href="{{ route('bookings.ticket', $booking) }}">@include('partials.icon', ['n' => 'download', 'small' => true])E-ticket PDF</a>
    @elseif ($booking->status === $S::Expired)
        <form method="post" action="{{ route('bookings.requote', $booking) }}">@csrf<button class="btn btn-primary" type="submit">Send a new quote</button></form>
    @endif
</header>

@if ($booking->flag)
    <div class="flash {{ in_array($booking->flag_tone, ['warn', 'bad'], true) ? 'flash-warn' : 'flash-ok' }}">{{ $booking->flag }}</div>
@endif

<div class="grid-3">
    {{-- Passports --}}
    <section class="card" aria-labelledby="pp">
        <div class="row"><h2 id="pp" class="grow" style="font-size:18px">{{ $booking->passports->count() > 1 ? 'Passports' : 'Passport' }}</h2>
            <span class="tiny muted">{{ $booking->passports->count() }} of {{ $booking->travellers ?? '?' }}</span></div>

        @forelse ($booking->passports as $p)
            <div class="stack-s" style="gap:10px">
                @if ($p->hasPhoto())
                    <a href="{{ route('bookings.photo', [$booking, $p]) }}" target="_blank" rel="noopener">
                        <img src="{{ route('bookings.photo', [$booking, $p]) }}" alt="Passport photo page of {{ $p->fullName() }}" style="width:100%;max-height:200px;object-fit:cover;border-radius:12px;background:#EDE7DA">
                    </a>
                @endif
                <div>
                    @foreach ([
                        ['Surname', $p->surname, 'surname'], ['Given names', $p->given_names, 'given_names'],
                        ['Nationality', $p->nationality.' · '.\App\Support\Countries::demonym($p->nationality), 'nationality'],
                        ['Date of birth', $p->dob()?->format('d M Y'), 'date_of_birth'], ['Sex', $p->sex, 'sex'],
                        ['Passport no.', $p->maskedNumber(), 'number'], ['Expiry', $p->expiry?->format('d M Y'), 'expiry'],
                    ] as [$label, $value, $key])
                        @php($c = $p->confidenceFor($key))
                        <div class="fieldrow">
                            <span class="lbl">{{ $label }}</span>
                            <span class="val">{{ $value ?: '—' }}</span>
                            @if ($key === 'expiry' && $p->expiry && $booking->depart_on && $p->expiry->lt(($booking->return_on ?? $booking->depart_on)->copy()->addMonths(6)))
                                <span class="conf t-warn">Expires soon</span>
                            @elseif ($c !== null)
                                <span class="conf {{ $c >= 90 ? 't-ok' : 't-warn' }}">{{ $c >= 100 ? 'Confirmed' : $c.'%' }}{{ $c < 90 ? ' · check' : '' }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="row small" style="color:{{ $p->mrz_valid ? 'var(--green-ink)' : 'var(--amber-ink)' }}">
                    @include('partials.icon', ['n' => $p->mrz_valid ? 'check' : 'clock', 'small' => true])
                    {{ $p->mrz_valid ? 'MRZ checksums passed' : 'MRZ not verified' }}{{ $p->confirmed_at ? ' · client confirmed at '.$p->confirmed_at->format('H:i') : '' }}
                </div>
                <details>
                    <summary class="small strong" style="cursor:pointer;color:var(--green);min-height:32px;display:flex;align-items:center">Correct these details</summary>
                    <form method="post" action="{{ route('bookings.passport', [$booking, $p]) }}" class="stack-s" style="margin-top:10px">
                        @csrf @method('put')
                        <div class="grid-2" style="gap:10px">
                            <label class="field">Surname<input class="input" name="surname" value="{{ $p->surname }}" required></label>
                            <label class="field">Given names<input class="input" name="given_names" value="{{ $p->given_names }}" required></label>
                            <label class="field">Passport no.<input class="input mono" name="number" value="{{ $p->number }}" required></label>
                            <label class="field">Nationality<input class="input" name="nationality" value="{{ $p->nationality }}" maxlength="3" required></label>
                            <label class="field">Date of birth<input class="input" type="date" name="date_of_birth" value="{{ $p->date_of_birth }}" required></label>
                            <label class="field">Expiry<input class="input" type="date" name="expiry" value="{{ $p->expiry?->toDateString() }}" required></label>
                            <label class="field">Sex<select class="select" name="sex"><option @selected($p->sex === 'F')>F</option><option @selected($p->sex === 'M')>M</option></select></label>
                        </div>
                        <button class="btn btn-s" type="submit">Save corrections</button>
                    </form>
                </details>
            </div>
            @if (! $loop->last)<hr style="border:0;border-top:1px solid var(--line);margin:4px 0">@endif
        @empty
            <div class="note">No passport yet. The bot asks for it once the trip details are clear.</div>
        @endforelse
    </section>

    {{-- Fare --}}
    <section class="stack" aria-labelledby="fare" style="gap:20px">
        <div class="card">
            <h2 id="fare" style="font-size:18px">Fare check</h2>
            <div class="figures">
                <div><span class="tiny">{{ $booking->paid_amount ? 'Quoted & paid' : 'Quoted' }}</span><span class="big">{{ $booking->amountLabel() }}</span></div>
                <div><span class="tiny">{{ $booking->ticketed_fare ? 'Ticketed at' : 'Live fare now' }}</span><span class="big">{{ \App\Support\Money::format($booking->ticketed_fare ?? $fareNow) }}</span></div>
                @php($m = $booking->ticketed_fare ? $booking->margin() : $margin)
                <div><span class="tiny">Margin</span><span class="big" style="color:{{ ($m ?? 0) >= 0 ? 'var(--green)' : 'var(--red)' }}">{{ $m !== null ? \App\Support\Money::signed($m) : '—' }}</span></div>
            </div>
            <div class="row small muted" style="border-top:1px solid var(--line-2);padding-top:12px">
                <span class="grow">{{ $offers->isNotEmpty() ? 'Offers · searched '.$offers->first()->created_at->format('H:i') : 'No search yet' }}</span>
                @if ($booking->origin && $booking->destination && $booking->depart_on && ! $booking->ticketed_at)
                    <form method="post" action="{{ route('bookings.search', $booking) }}">@csrf<button class="link-btn small" type="submit">Search again</button></form>
                @endif
            </div>
            @if ($offers->isNotEmpty())
                <form id="issue-form" method="post" action="{{ route('bookings.issue', $booking) }}" class="stack-s" role="radiogroup" aria-label="Offer to ticket">
                    @csrf
                    @foreach ($offers->take(5) as $o)
                        @php($within = $booking->paid_amount ? $o->amount <= $booking->paid_amount : ($booking->quote_amount && $o->amount <= $booking->quote_amount))
                        <label class="opt">
                            <input type="radio" name="offer_id" value="{{ $o->id }}" @checked($selected ? $selected->id === $o->id : $loop->first) @disabled($booking->ticketed_at)>
                            <span class="grow stack-s" style="gap:2px"><span class="strong">{{ $o->airline }}</span><span class="tiny muted">{{ $o->summary }} · {{ $o->baggage }}@if($o->depart_on && $booking->depart_on && ! $o->depart_on->isSameDay($booking->depart_on)) · {{ $o->depart_on->format('D j M') }}@endif</span></span>
                            <span class="stack-s right" style="gap:2px"><span class="strong">{{ \App\Support\Money::format($o->amount) }}</span>
                                @if ($booking->quote_amount)<span class="tiny strong" style="color:{{ $within ? 'var(--green-ink)' : 'var(--amber-ink)' }}">{{ $within ? 'Within quote' : 'Over by '.\App\Support\Money::format($o->amount - ($booking->paid_amount ?: $booking->quote_amount)) }}</span>@endif
                            </span>
                        </label>
                    @endforeach
                    @if ($canIssue)
                        <label class="row small" style="min-height:36px"><input type="checkbox" name="force" value="1" style="width:16px;height:16px;accent-color:var(--green)"> Issue even if the fare is over what the client paid</label>
                    @endif
                </form>
            @endif
        </div>

        @if ($confirmedPayment)
            <div class="card card-dark" style="flex-direction:row;align-items:center;gap:14px">
                <span class="check-big" style="width:40px;height:40px;background:var(--mint);color:#0B1F18">@include('partials.icon', ['n' => 'check'])</span>
                <div class="grow stack-s" style="gap:2px">
                    <span class="strong" style="color:#FFF">Payment confirmed · {{ \App\Support\Money::format($booking->paid_amount) }}</span>
                    <span class="small" style="color:var(--on-dark-2)">{{ $confirmedPayment->paid_at?->format('H:i') }} · ref {{ $confirmedPayment->reference }}{{ $confirmedPayment->method ? ' · '.ucfirst($confirmedPayment->method) : '' }}</span>
                </div>
            </div>
        @elseif ($openLink)
            <div class="card" style="gap:8px">
                <div class="row"><span class="strong grow">Pay link open · {{ $openLink->amountLabel() }}</span><span class="pill t-warn">{{ \App\Models\Payment::left($openLink->expires_at) }} left</span></div>
                <div class="row small"><span class="mono grow muted" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $openLink->url() }}</span>
                    <form method="post" action="{{ route('payments.resend', $openLink) }}">@csrf<button class="link-btn small" type="submit">Resend</button></form></div>
            </div>
        @endif

        @if ($booking->pnr)
            <div class="card" style="gap:8px">
                <div class="row"><span class="grow small muted">Booking reference</span><span class="mono big" style="font-size:22px;letter-spacing:.06em">{{ $booking->pnr }}</span></div>
                @if ($tickets = $booking->stateGet('ticket_numbers'))<div class="small muted">Tickets {{ implode(', ', $tickets) }}</div>@endif
            </div>
        @endif

        @if ($booking->status->isOpen() && (int) $booking->paid_amount === 0)
            <form method="post" action="{{ route('bookings.cancel', $booking) }}" onsubmit="return confirm('Cancel this booking?')">@csrf
                <button class="link-btn small" type="submit" style="color:var(--red)">Cancel booking</button></form>
        @endif
    </section>

    {{-- Timeline --}}
    <section class="card" aria-labelledby="tl">
        <div class="row"><h2 id="tl" class="grow" style="font-size:18px">Timeline</h2>
            <form method="post" action="{{ route('bookings.bot', $booking) }}">@csrf
                <button class="btn btn-s" type="submit">@include('partials.icon', ['n' => $booking->bot_paused ? 'play' : 'pause', 'small' => true]){{ $booking->bot_paused ? 'Resume bot' : 'Take over chat' }}</button></form>
        </div>
        <ol class="timeline">
            @foreach ($booking->events as $e)
                <li>
                    <div class="tl-rail"><span class="tl-dot {{ $e->type === 'error' ? 'err' : (in_array($e->type, ['review', 'handover', 'expired'], true) ? 'warn' : '') }}"></span><span class="tl-line"></span></div>
                    <div class="tl-body">
                        <span class="tiny mono muted">{{ $e->created_at->isToday() ? $e->created_at->format('H:i') : $e->created_at->format('j M H:i') }}</span>
                        <span class="strong" style="font-weight:500">{{ $e->title }}</span>
                        @if ($e->detail)<span class="small muted">{{ $e->detail }}</span>@endif
                    </div>
                </li>
            @endforeach
        </ol>
    </section>
</div>
@endsection
