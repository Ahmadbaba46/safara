<!doctype html>
<html lang="en">
<head>
    @include('partials.head')
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ config('safara.brand') }}</title>
</head>
<body>
<div class="phone">
    <div class="p-brand">
        <span class="p-mark" aria-hidden="true">{{ mb_substr(config('safara.brand'), 0, 1) }}</span>
        <span class="grow" style="font-family:var(--display);font-weight:700;font-size:20px">{{ \Illuminate\Support\Str::before(config('safara.brand'), ' ') }}</span>
        @isset($booking)<span class="mono tiny muted">{{ $booking->reference }}</span>@endisset
    </div>
    @if (session('notice'))
        <div class="flash flash-warn" role="status">{{ session('notice') }}</div>
    @endif
    @yield('content')
</div>
@stack('scripts')
</body>
</html>
