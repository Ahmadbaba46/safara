@extends('layouts.desk')
@php($section = 'simulator')
@section('title', 'Simulator')
@section('content')
<header class="row-end">
    <div class="grow stack-s" style="gap:4px">
        <h1>WhatsApp simulator</h1>
        <span class="muted">Be the client. Everything runs through the real conversation engine{{ in_array(false, $fake, true) ? '' : ' with test drivers, so nothing leaves this machine' }}.</span>
    </div>
    <form method="get" class="row" style="gap:8px">
        <label class="field" style="flex-direction:row;align-items:center;gap:8px">Client phone
            <input class="input" name="phone" value="{{ $phone }}" style="width:180px" inputmode="tel"></label>
        <button class="btn" type="submit">Switch</button>
    </form>
</header>

@if (! $fake['whatsapp'])
    <div class="flash flash-warn">The WhatsApp driver is live: bot replies from here really go to +{{ $phone }}.</div>
@endif

<div style="display:grid;grid-template-columns:minmax(0,420px) minmax(0,1fr);gap:28px;align-items:start">
    <div style="border-radius:28px;border:1px solid var(--line);overflow:hidden;background:var(--chat)">
        <div class="row" style="height:60px;background:var(--dark);color:#FFF;padding:0 16px;gap:10px">
            <span class="avatar" style="width:36px;height:36px;background:var(--mint);color:#0B1F18;font-family:var(--display);font-weight:700">{{ mb_substr(config('safara.brand'), 0, 1) }}</span>
            <span class="grow stack-s" style="gap:0"><span class="strong">{{ config('safara.brand') }}</span><span class="tiny" style="color:var(--on-dark-2)">Business account</span></span>
        </div>
        <div class="chat" id="sim-chat" style="border-radius:0;height:560px;overflow-y:auto">
            @forelse ($messages as $m)
                @if ($loop->first || ! $m->created_at->isSameDay($messages[$loop->index - 1]->created_at))
                    <span class="day">{{ $m->created_at->isToday() ? 'Today' : $m->created_at->format('D j M') }}</span>
                @endif
                @include('partials.bubble', ['m' => $m, 'interactive' => $lastOut && $m->id === $lastOut->id])
            @empty
                <span class="muted small" style="align-self:center;text-align:center;padding:60px 20px">Say hello, or try:<br><em>“Salam, I need a flight Kano to Jeddah, 12 October, just me.”</em></span>
            @endforelse
        </div>
        <form method="post" action="{{ route('simulator.send') }}" class="composer" style="padding:10px;background:var(--chat)">
            @csrf
            <input type="hidden" name="phone" value="{{ $phone }}"><input type="hidden" name="kind" value="text">
            <label class="sr-only" for="sim-text">Message</label>
            <input class="input grow" id="sim-text" name="text" placeholder="Message" autocomplete="off" autofocus required>
            <button class="btn btn-primary" type="submit" style="border-radius:23px;height:46px">Send</button>
        </form>
    </div>

    <div class="stack" style="gap:20px">
        <section class="card" aria-labelledby="send-photo">
            <h2 id="send-photo" style="font-size:18px">Send a passport photo</h2>
            <form method="post" action="{{ route('simulator.send') }}" enctype="multipart/form-data" class="stack-s" style="gap:12px">
                @csrf
                <input type="hidden" name="phone" value="{{ $phone }}">
                @if ($fake['passport'])
                    <label class="field">Name on the test passport (surname first)<input class="input" name="text" placeholder="BELLO AISHA"></label>
                @else
                    <label class="field">Passport photo<input class="input" type="file" name="photo" accept="image/*" style="padding-top:9px"></label>
                @endif
                <div class="row">
                    <button class="btn btn-primary" type="submit" name="kind" value="photo">Send clear photo</button>
                    @if ($fake['passport'])<button class="btn" type="submit" name="kind" value="blurry">Send blurry photo</button>@endif
                </div>
            </form>
        </section>

        <section class="card" aria-labelledby="scen">
            <h2 id="scen" style="font-size:18px">Rehearse what can go wrong</h2>
            <div class="stack-s" style="gap:10px">
                @if ($fake['flights'])
                    <form method="post" action="{{ route('simulator.control') }}" class="row">@csrf<input type="hidden" name="action" value="bump">
                        <span class="grow small">Fare rises after the client pays{{ $bump ? ' (armed)' : '' }}</span>
                        <select class="select" name="amount" style="width:auto;height:36px;font-size:13px" aria-label="How far above the quote">
                            <option value="2000">₦2,000 over the quote (absorbed)</option><option value="38000" selected>₦38,000 over the quote (review)</option>
                        </select>
                        <button class="btn btn-s" type="submit">Arm</button></form>
                    <form method="post" action="{{ route('simulator.control') }}" class="row">@csrf<input type="hidden" name="action" value="empty">
                        <span class="grow small">Next flight search finds nothing</span><button class="btn btn-s" type="submit">Arm</button></form>
                @endif
                <form method="post" action="{{ route('simulator.control') }}" class="row">@csrf<input type="hidden" name="action" value="expire">
                    <span class="grow small">End the current price hold now</span><button class="btn btn-s" type="submit">Expire</button></form>
                <form method="post" action="{{ route('simulator.control') }}" class="row">@csrf<input type="hidden" name="action" value="tick">
                    <span class="grow small">Run the scheduler once (holds, overnight pause, passport clean-up)</span><button class="btn btn-s" type="submit">Run</button></form>
                <form method="post" action="{{ route('simulator.control') }}" class="row" onsubmit="return confirm('Delete this test client and everything about them?')">@csrf<input type="hidden" name="action" value="reset">
                    <span class="grow small">Start over with this phone number</span><button class="btn btn-s btn-danger" type="submit">Reset</button></form>
            </div>
        </section>

        @if ($booking)
            <section class="card" style="gap:8px">
                <div class="row"><span class="grow strong">Booking {{ $booking->reference }}</span><span class="pill t-{{ $booking->status->tone() }}">{{ $booking->status->label() }}</span></div>
                <span class="small muted">{{ $booking->tripLine() }}</span>
                <div class="row"><a class="btn btn-s" href="{{ route('bookings.show', $booking) }}">Open on the desk</a>
                    @if ($link = $booking->openPayment())<a class="btn btn-s btn-primary" href="{{ $link->url() }}" target="_blank" rel="noopener">Open pay link</a>@endif
                </div>
            </section>
        @endif

        <div class="note">
            Keywords work anywhere: <span class="mono">HELP</span> hands the chat to you, <span class="mono">CHANGE</span> asks about a change, <span class="mono">HAUSA</span> / <span class="mono">ENGLISH</span> switch language.
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>const c = document.getElementById('sim-chat'); if (c) c.scrollTop = c.scrollHeight;</script>
@endpush
