@extends('layouts.desk')
@php($section = 'settings')
@section('title', 'Settings')
@section('content')
<form method="post" action="{{ route('settings.update') }}" class="stack" style="gap:20px">
    @csrf @method('put')
    <header class="row-end">
        <div class="grow stack-s" style="gap:4px">
            <h1>Settings</h1>
            <span class="muted">How quotes are priced, when tickets issue on their own, and what gets kept</span>
        </div>
        <button class="btn btn-primary" type="submit">Save settings</button>
    </header>

    <div class="grid-2">
        <div class="stack" style="gap:20px">
            <section class="card" aria-labelledby="s1">
                <h2 id="s1" style="font-size:18px">Pricing</h2>
                <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px">
                    <label class="field">Markup<span class="affix"><input type="number" name="markup_percent" step="0.1" min="0" max="100" value="{{ old('markup_percent', $s['markup_percent']) }}" required><span>%</span></span></label>
                    <label class="field">Minimum margin<span class="affix"><span>₦</span><input type="number" name="min_margin" min="0" step="500" value="{{ old('min_margin', $s['min_margin']) }}" required></span></label>
                    <label class="field">Round up to
                        <select class="select" name="round_to">
                            @foreach ([1000 => '₦1,000', 5000 => '₦5,000', 500 => '₦500', 0 => 'No rounding'] as $v => $l)<option value="{{ $v }}" @selected((int) $s['round_to'] === $v)>{{ $l }}</option>@endforeach
                        </select>
                    </label>
                </div>
                <div class="note">Example: a {{ \App\Support\Money::format($example) }} fare is quoted as <strong>{{ \App\Support\Money::format($exampleQuote) }}</strong>.</div>
                <div class="field">Exchange rates (for fares Duffel prices in other currencies)
                    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;font-weight:400">
                        <span class="affix"><span>1 USD = ₦</span><input type="number" name="fx_usd" step="0.01" value="{{ $s['fx_rates']['USD'] ?? 1550 }}" aria-label="Naira per US dollar"></span>
                        <span class="affix"><span>1 GBP = ₦</span><input type="number" name="fx_gbp" step="0.01" value="{{ $s['fx_rates']['GBP'] ?? 1980 }}" aria-label="Naira per pound"></span>
                        <span class="affix"><span>1 EUR = ₦</span><input type="number" name="fx_eur" step="0.01" value="{{ $s['fx_rates']['EUR'] ?? 1690 }}" aria-label="Naira per euro"></span>
                    </div>
                </div>
            </section>

            <section class="card" aria-labelledby="s2">
                <h2 id="s2" style="font-size:18px">Quotes &amp; payment</h2>
                <div class="grid-2" style="gap:12px">
                    <label class="field">Hold the quoted price for
                        <select class="select" name="hold_minutes">
                            @foreach ([120 => '2 hours', 60 => '1 hour', 240 => '4 hours', 30 => '30 minutes'] as $v => $l)<option value="{{ $v }}" @selected((int) $s['hold_minutes'] === $v)>{{ $l }}</option>@endforeach
                        </select>
                    </label>
                    <div class="field">Payment provider<span class="affix" style="border-style:dashed;color:var(--muted);font-weight:500">{{ $gatewayName }}</span></div>
                </div>
                <fieldset style="border:0;margin:0;padding:0;display:flex;gap:20px;flex-wrap:wrap">
                    <legend class="small strong" style="padding:0;margin-bottom:8px">Payment methods</legend>
                    @foreach (['card' => 'Card', 'transfer' => 'Bank transfer', 'ussd' => 'USSD'] as $v => $l)
                        <label class="row" style="gap:8px;min-height:44px"><input type="checkbox" name="payment_methods[]" value="{{ $v }}" @checked(in_array($v, (array) $s['payment_methods'], true)) style="width:18px;height:18px;accent-color:var(--green)">{{ $l }}</label>
                    @endforeach
                </fieldset>
                <label class="field">Refunds reach clients within<input class="input" name="refund_time" value="{{ $s['refund_time'] ?? 'a few working days' }}" maxlength="60"></label>
            </section>

            <section class="card" aria-labelledby="s5" style="gap:10px">
                <h2 id="s5" style="font-size:18px">Connections</h2>
                @foreach ([
                    ['WhatsApp Cloud API', config('safara.drivers.whatsapp') === 'meta', config('safara.meta.business_number') ? '+'.config('safara.meta.business_number') : 'SAFARA_WHATSAPP_DRIVER'],
                    ['Duffel', config('safara.drivers.flights') === 'duffel', config('safara.drivers.flights') === 'duffel' ? (str_starts_with((string) config('safara.duffel.token'), 'duffel_test') ? 'Test mode' : 'Live mode') : 'SAFARA_FLIGHTS_DRIVER'],
                    ['Passport reader (OpenRouter)', config('safara.drivers.passport_reader') === 'openrouter', config('safara.openrouter.vision_models.0')],
                    ['Payments', config('safara.drivers.payments') !== 'fake', config('safara.drivers.payments') === 'fake' ? 'SAFARA_PAYMENTS_DRIVER' : class_basename(config('safara.drivers.payments'))],
                ] as [$name, $live, $detail])
                    <div class="row" style="padding:8px 0;border-bottom:1px solid var(--line-2)">
                        <span class="dot {{ $live ? '' : 'test' }}" style="background:{{ $live ? '#1F8A63' : '#C9A227' }}"></span>
                        <span class="grow strong">{{ $name }}</span>
                        <span class="small mono muted">{{ $live ? $detail : 'Test driver · set '.$detail }}</span>
                    </div>
                @endforeach
                <span class="tiny muted">Connections are set in the server’s .env file.</span>
            </section>
        </div>

        <div class="stack" style="gap:20px">
            <section class="card" aria-labelledby="s3" style="gap:4px">
                <h2 id="s3" style="font-size:18px;margin-bottom:8px">Ticketing rules</h2>
                @foreach ([
                    ['auto_issue', 'Issue tickets on their own', 'When the live fare is at or below what the client paid'],
                    ['absorb', 'Absorb small rises', 'Ticket anyway and take it from the margin, up to the limit below'],
                    ['alert_operator', 'Alert me on WhatsApp for reviews', 'Anything above the absorb limit waits for you'],
                    ['night_pause', 'Pause auto-ticketing overnight', 'Paid bookings wait for the morning'],
                ] as [$key, $title, $sub])
                    <div class="switch-row">
                        <div class="grow stack-s" style="gap:2px"><span class="strong" id="lbl-{{ $key }}">{{ $title }}</span><span class="small muted">{{ $sub }}</span></div>
                        <label class="switch"><input type="checkbox" role="switch" name="{{ $key }}" value="1" aria-labelledby="lbl-{{ $key }}" @checked($s[$key])><span></span></label>
                    </div>
                @endforeach
                <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding-top:14px">
                    <label class="field">Absorb up to<span class="affix"><span>₦</span><input type="number" name="absorb_limit" min="0" step="500" value="{{ $s['absorb_limit'] }}"></span></label>
                    <label class="field">Pause from<input class="input" type="time" name="night_start" value="{{ $s['night_start'] }}"></label>
                    <label class="field">Until<input class="input" type="time" name="night_end" value="{{ $s['night_end'] }}"></label>
                </div>
            </section>

            <section class="card" aria-labelledby="s4" style="gap:10px">
                <h2 id="s4" style="font-size:18px">Passport data</h2>
                <span class="small muted">Clients are asked before their passport is kept for next time.</span>
                @foreach ([
                    ['expiry', 'Until the passport expires, if they agree', 'Repeat clients never resend a photo'],
                    ['after_travel', '30 days after travel', 'Enough for changes and refunds'],
                    ['after_ticket', 'Delete once the ticket is issued', 'Keep nothing beyond the booking'],
                ] as [$v, $l, $sub])
                    <label class="opt"><input type="radio" name="retention" value="{{ $v }}" @checked($s['retention'] === $v)>
                        <span class="grow stack-s" style="gap:0"><span class="strong">{{ $l }}</span><span class="tiny muted">{{ $sub }}</span></span></label>
                @endforeach
            </section>
        </div>
    </div>
</form>
@endsection
