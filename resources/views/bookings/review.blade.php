@extends('layouts.desk')
@section('title', 'Fare review '.$booking->reference)
@section('content')
@php
    $section = 'bookings';
    $waiting = $booking->status === \App\Enums\BookingStatus::AwaitingChoice;
    $choices = [
        'choose' => ['Let '.($booking->passports->first()?->firstName() ?: 'the client').' choose', 'Pay the difference, move to a date within budget, or take a refund', 'Recommended', 'var(--green-ink)'],
        'absorb' => ['Absorb the '.\App\Support\Money::format($difference), 'Ticket now at today’s fare', 'Margin '.\App\Support\Money::signed($margin), $margin < 0 ? 'var(--red)' : 'var(--green-ink)'],
        'difference' => ['Ask for '.\App\Support\Money::format($difference).' more', 'Sends a pay link; tickets once it’s paid', 'Margin kept', 'var(--green-ink)'],
        'refund' => ['Refund in full', 'Returns '.\App\Support\Money::format($booking->paid_amount).' and closes the booking', 'No booking', 'var(--ink-2)'],
    ];
@endphp
<header class="row-end">
    <div class="grow stack-s" style="gap:6px">
        <div class="crumbs"><a href="{{ route('desk') }}">Desk</a><span aria-hidden="true">/</span><a class="mono" href="{{ route('bookings.show', $booking) }}">{{ $booking->reference }}</a></div>
        <div class="row" style="gap:14px">
            <h1>{{ $booking->client->displayName() }}</h1>
            <span class="pill pill-l t-warn">{{ $waiting ? 'Waiting on client' : 'Fare review · waiting '.$booking->updated_at->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</span>
        </div>
        <span class="small" style="color:var(--ink-2)">{{ $booking->tripLine() }}</span>
    </div>
    <a class="btn" href="{{ route('bookings.chat', $booking) }}">@include('partials.icon', ['n' => 'chat', 'small' => true])Open chat</a>
</header>

<form method="post" action="{{ route('bookings.decide', $booking) }}" class="grid-2" style="grid-template-columns:1.25fr 1fr" id="review-form">
    @csrf
    <section class="card" aria-labelledby="dec" style="padding:22px;gap:16px">
        <h2 id="dec">{{ $booking->flag && str_contains($booking->flag, 'failed') ? 'Ticketing needs you' : 'The fare rose after payment' }}</h2>
        <div class="figures note" style="padding:16px">
            <div><span class="tiny">Client paid</span><span class="big">{{ \App\Support\Money::format($booking->paid_amount) }}</span></div>
            <div><span class="tiny">Cheapest fare now</span><span class="big">{{ \App\Support\Money::format($selected?->amount) }}</span></div>
            <div><span class="tiny">Short by</span><span class="big" style="color:var(--amber)">{{ \App\Support\Money::format($difference) }}</span></div>
        </div>
        <span class="small" style="color:var(--ink-2)">
            {{ $booking->flag }}.
            This is above your {{ \App\Support\Money::format(app(\App\Services\Settings::class)->get('absorb_limit')) }} absorb limit, so it’s waiting for you.
            <a href="{{ route('settings') }}" style="font-weight:600;text-decoration:none">Change rules</a>
        </span>
        @if ($waiting)
            <div class="flash flash-warn">Options were sent on WhatsApp. You can still decide here if the client asks you to.</div>
        @endif
        <div role="radiogroup" aria-label="What to do" class="stack-s" style="gap:10px">
            @foreach ($choices as $key => [$title, $sub, $effect, $color])
                <label class="opt">
                    <input type="radio" name="choice" value="{{ $key }}" @checked($loop->first) data-preview="{{ $key }}">
                    <span class="grow stack-s" style="gap:2px"><span class="strong" style="font-size:15px">{{ $title }}</span><span class="small muted">{{ $sub }}</span></span>
                    <span class="small strong" style="color:{{ $color }}">{{ $effect }}</span>
                </label>
            @endforeach
        </div>
    </section>

    <section class="stack" aria-labelledby="pv" style="gap:16px">
        <div class="card">
            <h2 id="pv" style="font-size:18px">What {{ $booking->passports->first()?->firstName() ?: 'the client' }} will get</h2>
            @foreach ($previews as $key => $p)
                <div class="chat" data-preview-panel="{{ $key }}" @if(! $loop->first) hidden @endif>
                    <div class="bubble out" style="max-width:92%">
                        <div class="b-body">{{ $p['body'] }}</div>
                        @foreach ($p['buttons'] as $b)<span class="b-btn">{{ $b }}</span>@endforeach
                    </div>
                </div>
            @endforeach
        </div>
        <div class="card" style="gap:10px">
            <div class="row"><span class="strong grow">Other dates within what they paid</span>
                <button class="link-btn small" type="submit" name="choice" value="alternatives" formnovalidate>Check nearby dates</button></div>
            @forelse ($alternatives as $o)
                <div class="row small" style="padding:8px 0;border-bottom:1px solid var(--line-2)"><span class="grow"><strong>{{ $o->depart_on->format('D j M') }}</strong> · {{ $o->airline }} · {{ $o->summary }}</span><span class="strong">{{ \App\Support\Money::format($o->amount) }}</span></div>
            @empty
                <span class="small muted">None checked yet. “Let the client choose” checks automatically.</span>
            @endforelse
        </div>
        @foreach ($previews as $key => $p)
            <button class="btn btn-primary p-cta" type="submit" data-preview-panel="{{ $key }}" @if(! $loop->first) hidden @endif>{{ $p['cta'] }}</button>
        @endforeach
    </section>
</form>
@endsection

@push('scripts')
<script>
    document.querySelectorAll('input[data-preview]').forEach((r) => r.addEventListener('change', () => {
        document.querySelectorAll('[data-preview-panel]').forEach((el) => { el.hidden = el.dataset.previewPanel !== r.dataset.preview; });
    }));
</script>
@endpush
