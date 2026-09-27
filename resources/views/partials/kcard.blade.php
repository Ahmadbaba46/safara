@php
    $href = $b->status === \App\Enums\BookingStatus::FareReview ? route('bookings.review', $b) : route('bookings.show', $b);
    $when = $b->status === \App\Enums\BookingStatus::Ticketed
        ? $b->ticketed_at?->format('H:i')
        : $b->updated_at->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true);
@endphp
<a class="kcard" href="{{ $href }}">
    <div class="kcard-top"><span class="grow mono">{{ $b->reference }}</span><span>{{ $when }}</span></div>
    <span class="kcard-name">{{ $b->client->displayName() }}</span>
    <div class="row" style="gap:8px;align-items:baseline"><span class="mono strong">{{ $b->routeCodes() }}</span><span class="tiny muted">{{ $b->dateLabel() }}</span></div>
    <div class="kcard-foot"><span class="grow">{{ $b->travellersLabel() }}</span><span class="strong" style="color:var(--ink)">{{ $b->amountLabel() }}</span></div>
    @if ($b->bot_paused)
        <span class="flag t-warn">{{ $b->flag ?? 'Bot paused · reply by hand' }}</span>
    @elseif ($b->status === \App\Enums\BookingStatus::Ticketed && $b->pnr)
        <span class="flag t-mute">PNR {{ $b->pnr }}</span>
    @elseif ($b->flag)
        <span class="flag t-{{ $b->flag_tone ?? 'info' }}">{{ $b->flag }}</span>
    @elseif ($b->status === \App\Enums\BookingStatus::Expired)
        <span class="flag t-mute">Price hold ended</span>
    @elseif ($b->status === \App\Enums\BookingStatus::AwaitingPayment && $b->hold_expires_at)
        <span class="flag {{ $b->hold_expires_at->lt(now()->addMinutes(30)) ? 't-warn' : 't-mute' }}">Link expires in {{ \App\Models\Payment::left($b->hold_expires_at) }}</span>
    @endif
</a>
