<!doctype html>
<html lang="en">
<head>
    @include('partials.head')
    <title>Log in · {{ config('safara.brand') }}</title>
</head>
<body>
<div class="login">
    <form class="login-card" method="post" action="{{ url('/login') }}">
        @csrf
        <div class="p-brand">
            <span class="brand-mark" aria-hidden="true">{{ mb_substr(config('safara.brand'), 0, 1) }}</span>
            <span style="display:flex;flex-direction:column"><span style="font-family:var(--display);font-weight:700;font-size:22px">{{ \Illuminate\Support\Str::before(config('safara.brand'), ' ') }}</span><span class="tiny muted">Flight desk</span></span>
        </div>
        <h1 style="font-size:26px">Log in</h1>
        @include('partials.flash')
        <label class="field">Email
            <input class="input" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
        </label>
        <label class="field">Password
            <input class="input" type="password" name="password" autocomplete="current-password" required>
        </label>
        <label class="row small" style="min-height:44px"><input type="checkbox" name="remember" value="1" style="width:18px;height:18px;accent-color:var(--green)"> Keep me logged in</label>
        <button class="btn btn-primary btn-block" type="submit">Log in</button>
    </form>
</div>
</body>
</html>
