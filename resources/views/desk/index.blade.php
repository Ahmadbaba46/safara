@extends('layouts.desk')
@php($section = 'desk')
@section('title', 'Today')
@section('content')
<header class="row-end">
    <div class="grow stack-s" style="gap:4px">
        <h1>Today’s desk</h1>
        <span class="muted">{{ now()->format('l j F') }} · {{ collect($pipeline)->except('ticketed')->sum(fn ($c) => $c['items']->count()) }} bookings in motion</span>
    </div>
    <form class="search" method="get" action="{{ route('bookings.index') }}" role="search">
        @include('partials.icon', ['n' => 'search', 'small' => true])
        <label class="sr-only" for="q">Search bookings</label>
        <input id="q" type="search" name="q" placeholder="Name, phone, booking or PNR">
    </form>
    <a class="btn btn-primary" href="{{ route('bookings.create') }}">@include('partials.icon', ['n' => 'plus', 'small' => true])Manual quote</a>
</header>

<section class="tiles" aria-label="Summary">
    @foreach ($stats as [$label, $value, $foot, $tone])
        <div class="tile {{ $tone }}">
            <span class="tile-label">{{ $label }}</span>
            <span class="tile-value">{{ $value }}</span>
            <span class="tile-foot">{{ $foot }}</span>
        </div>
    @endforeach
</section>

<section class="stack" aria-labelledby="pipeline-title" style="gap:14px">
    <div class="row">
        <h2 id="pipeline-title" class="grow">Pipeline</h2>
        <div class="seg" role="group" aria-label="Filter routes">
            @foreach (['all' => 'All routes', 'hajj' => 'Hajj & Umrah', 'domestic' => 'Domestic', 'international' => 'International'] as $key => $label)
                <a href="{{ route('desk', $key === 'all' ? [] : ['route' => $key]) }}" aria-current="{{ $filter === $key ? 'true' : 'false' }}">{{ $label }}</a>
            @endforeach
        </div>
    </div>
    <div class="kanban">
        @foreach ($pipeline as $key => $col)
            <div class="kcol">
                <div class="kcol-head">
                    <span class="kcol-dot" style="background: {{ $col['dot'] }}"></span>
                    <span class="grow small strong">{{ $col['label'] }}</span>
                    <span class="count">{{ $col['items']->count() }}</span>
                </div>
                @forelse ($col['items']->take(6) as $b)
                    @include('partials.kcard', ['b' => $b])
                @empty
                    <span class="kempty">{{ $key === 'ticketed' ? 'No tickets issued yet today.' : 'Nothing here right now.' }}</span>
                @endforelse
                @if ($col['items']->count() > 6)
                    <a class="more" href="{{ route('bookings.index', ['tab' => $key === 'ticketed' ? 'ticketed' : 'open']) }}">+{{ $col['items']->count() - 6 }} more</a>
                @endif
            </div>
        @endforeach
    </div>
</section>
@endsection

@push('scripts')
<script>
    // Keep the desk fresh without a manual reload.
    setTimeout(() => { if (!document.hidden) location.reload(); }, 60000);
</script>
@endpush
