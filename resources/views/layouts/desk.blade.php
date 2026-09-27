<!doctype html>
<html lang="en">
<head>
    @include('partials.head')
    <title>@yield('title', 'Desk') · {{ config('safara.brand') }}</title>
</head>
<body>
<div class="desk">
    <nav class="side" aria-label="Main">
        <a class="brand" href="{{ route('desk') }}">
            <span class="brand-mark" aria-hidden="true">{{ mb_substr(config('safara.brand'), 0, 1) }}</span>
            <span><span class="brand-name">{{ \Illuminate\Support\Str::before(config('safara.brand'), ' ') }}</span><span class="brand-sub">Flight desk</span></span>
        </a>
        @php($section = $section ?? '')
        <div class="nav">
            <a href="{{ route('desk') }}" @if($section === 'desk') aria-current="page" @endif>@include('partials.icon', ['n' => 'desk'])Desk
                @if ($attention)<span class="badge" title="Bookings waiting for you">{{ $attention }}</span>@endif
            </a>
            <a href="{{ route('bookings.index') }}" @if($section === 'bookings') aria-current="page" @endif>@include('partials.icon', ['n' => 'ticket'])Bookings</a>
            <a href="{{ route('clients.index') }}" @if($section === 'clients') aria-current="page" @endif>@include('partials.icon', ['n' => 'user'])Clients</a>
            <a href="{{ route('payments.index') }}" @if($section === 'payments') aria-current="page" @endif>@include('partials.icon', ['n' => 'card'])Payments</a>
            <a href="{{ route('templates.index') }}" @if($section === 'templates') aria-current="page" @endif>@include('partials.icon', ['n' => 'chat'])Message templates</a>
            <a href="{{ route('settings') }}" @if($section === 'settings') aria-current="page" @endif>@include('partials.icon', ['n' => 'settings'])Settings</a>
            @if (config('safara.simulator') && ! app()->environment('production'))
                <a href="{{ route('simulator') }}" @if($section === 'simulator') aria-current="page" @endif>@include('partials.icon', ['n' => 'flask'])Simulator</a>
            @endif
        </div>
        <div class="conn">
            <span class="conn-title">Connections</span>
            @foreach ($connections as [$name, $live])
                <div class="conn-row"><span class="dot {{ $live ? '' : 'test' }}"></span><span class="grow">{{ $name }}</span><span style="color:var(--on-dark-3)">{{ $live ? 'Live' : 'Test' }}</span></div>
            @endforeach
        </div>
        <div class="me">
            <span class="avatar">{{ collect(explode(' ', auth()->user()->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</span>
            <div class="grow" style="display:flex;flex-direction:column">
                <span style="font-weight:500">{{ auth()->user()->name }}</span>
                <form method="post" action="{{ route('logout') }}">@csrf<button type="submit">Log out</button></form>
            </div>
        </div>
    </nav>
    <main class="main">
        @include('partials.flash')
        @yield('content')
    </main>
</div>
@stack('scripts')
</body>
</html>
