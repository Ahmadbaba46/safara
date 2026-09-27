{{-- One chat message. $m = App\Models\Message; $interactive = replies are clickable (simulator) --}}
@php
    $p = $m->payload ?? [];
    $interactive = $interactive ?? false;
@endphp
<div class="bubble {{ $m->isInbound() ? 'in' : 'out' }} {{ $m->status === 'failed' ? 'failed' : '' }}">
    @if ($m->type === 'image')
        <div class="b-photo">@include('partials.icon', ['n' => 'user'])&nbsp;Passport photo</div>
    @endif
    @if ($m->type === 'document')
        <div class="b-doc"><span class="pdf">PDF</span><span class="grow stack-s" style="gap:2px"><span class="strong small">{{ $p['filename'] ?? $m->body }}</span><span class="tiny muted">E-ticket</span></span></div>
    @elseif ($m->type !== 'image' || ($m->body && $m->body !== 'Photo'))
        <div class="b-body">{{ $m->body }}<span class="b-time">@if($m->sent_by && $m->sent_by !== 'bot' && ! $m->isInbound()){{ $m->sent_by }} · @endif{{ $m->created_at->format('H:i') }}@if($m->status === 'failed') · not delivered @endif</span></div>
    @else
        <span class="b-meta" style="align-self:flex-end">{{ $m->created_at->format('H:i') }}</span>
    @endif

    @if ($m->type === 'cta' && ! empty($p['url']))
        <a class="b-btn" href="{{ $p['url'] }}" target="_blank" rel="noopener">{{ $p['label'] ?? 'Open' }}</a>
    @endif
    @foreach (($p['buttons'] ?? []) as $b)
        @if ($interactive)
            <form method="post" action="{{ route('simulator.send') }}">@csrf
                <input type="hidden" name="phone" value="{{ $m->client->phone }}"><input type="hidden" name="kind" value="reply">
                <input type="hidden" name="reply_id" value="{{ $b['id'] }}"><input type="hidden" name="text" value="{{ $b['title'] }}">
                <button class="b-btn" type="submit">{{ $b['title'] }}</button></form>
        @else
            <span class="b-btn">{{ $b['title'] }}</span>
        @endif
    @endforeach
    @if (! empty($p['rows']))
        <div class="b-meta" style="padding-top:6px;border-top:1px solid #EAE5D9">{{ $p['label'] ?? 'Choose' }}</div>
        @foreach ($p['rows'] as $r)
            @if ($interactive)
                <form method="post" action="{{ route('simulator.send') }}">@csrf
                    <input type="hidden" name="phone" value="{{ $m->client->phone }}"><input type="hidden" name="kind" value="reply">
                    <input type="hidden" name="reply_id" value="{{ $r['id'] }}"><input type="hidden" name="text" value="{{ $r['title'] }}">
                    <button class="b-btn" type="submit" style="justify-content:flex-start;font-weight:500;color:var(--ink)">{{ $r['title'] }}</button></form>
            @else
                <span class="b-btn" style="justify-content:flex-start;font-weight:500;color:var(--ink)">{{ $r['title'] }}</span>
            @endif
        @endforeach
    @endif
</div>
