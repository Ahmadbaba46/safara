@extends('layouts.desk')
@php($section = 'templates')
@section('title', 'Message templates')
@section('content')
<div style="display:grid;grid-template-columns:280px minmax(0,1fr) 340px;gap:28px;align-items:start">
    <section class="stack" aria-label="Templates" style="gap:10px">
        <h1 style="font-size:30px">Templates</h1>
        <span class="small muted">What the bot says at each step, in English and Hausa</span>
        <div class="stack-s" style="gap:2px">
            @php($stage = null)
            @foreach ($templates as $t)
                @if ($t->stage !== $stage)
                    @php($stage = $t->stage)
                    <span class="tiny strong muted" style="text-transform:uppercase;letter-spacing:.06em;padding:12px 10px 4px">{{ $stage }}</span>
                @endif
                <a href="{{ route('templates.index', ['t' => $t->key, 'lang' => $lang]) }}" @if($current?->id === $t->id) aria-current="page" @endif
                   style="display:flex;align-items:center;min-height:40px;padding:6px 10px;border-radius:10px;text-decoration:none;color:var(--ink);font-weight:{{ $current?->id === $t->id ? 600 : 500 }};border:1px solid {{ $current?->id === $t->id ? '#CFC8B8' : 'transparent' }};background:{{ $current?->id === $t->id ? '#FFF' : 'transparent' }}">
                    <span class="grow">{{ $t->name }}</span>
                    @if ($t->kind === 'template')<span class="pill t-{{ $t->kindTone() }}" style="font-size:11px;padding:2px 7px">Meta</span>@endif
                </a>
            @endforeach
        </div>
    </section>

    @if ($current)
        <form method="post" action="{{ route('templates.update', $current) }}" class="stack" id="tpl-form" style="gap:18px">
            @csrf @method('put')
            <input type="hidden" name="lang" value="{{ $lang }}" id="lang-input">
            <header class="row">
                <div class="grow stack-s" style="gap:6px">
                    <h2 style="font-size:26px">{{ $current->name }}</h2>
                    <div class="row" style="gap:8px"><span class="pill t-{{ $current->kindTone() }}">{{ $current->kindLabel() }}</span><span class="small muted">{{ $current->stage }}</span></div>
                </div>
                <div class="seg" role="group" aria-label="Language">
                    <button type="button" data-lang="en" aria-pressed="{{ $lang === 'en' ? 'true' : 'false' }}">English</button>
                    <button type="button" data-lang="ha" aria-pressed="{{ $lang === 'ha' ? 'true' : 'false' }}">Hausa</button>
                </div>
            </header>

            <div class="card" style="gap:16px">
                @foreach (['en' => 'English', 'ha' => 'Hausa'] as $code => $label)
                    <div class="stack-s" data-lang-panel="{{ $code }}" @if($lang !== $code) hidden @endif style="gap:14px">
                        <label class="field">Message ({{ $label }})
                            <textarea class="textarea" name="body_{{ $code }}" rows="6" maxlength="1024" data-body @if($code === 'en') required @endif>{{ old('body_'.$code, $current->{'body_'.$code}) }}</textarea>
                        </label>
                        @if ($current->buttons_en)
                            <div class="field">Reply buttons ({{ $label }}, 20 characters max)
                                <div class="row wrap" style="gap:8px">
                                    @foreach ($current->buttons_en as $i => $b)
                                        <input class="input" style="width:auto;flex:1 1 150px;font-weight:600;color:var(--green)" name="buttons_{{ $code }}[]" maxlength="20" value="{{ ($current->{'buttons_'.$code} ?? [])[$i] ?? '' }}" data-button aria-label="Button {{ $i + 1 }}">
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
                @if ($vars = $current->variables())
                    <div class="stack-s">
                        <span class="small strong">Fills in automatically</span>
                        <div class="row wrap" style="gap:8px">
                            @foreach ($vars as $v)
                                <span class="row small" style="gap:6px;padding:6px 10px;border-radius:8px;background:var(--paper)"><span class="mono strong" style="color:var(--green)">{{ '{'.$v.'}' }}</span><span class="muted">{{ \App\Http\Controllers\TemplatesController::SAMPLE[$v] ?? '' }}</span></span>
                            @endforeach
                        </div>
                    </div>
                @endif
                @if ($current->kind === 'template')
                    <div class="grid-2" style="gap:12px">
                        <label class="field">Approved template name at Meta<input class="input mono" name="meta_name" value="{{ $current->meta_name }}" placeholder="e.g. price_hold_expired"></label>
                        <label class="field">Meta status
                            <select class="select" name="meta_status">
                                @foreach (['in_review' => 'In review', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $k => $v)<option value="{{ $k }}" @selected($current->meta_status === $k)>{{ $v }}</option>@endforeach
                            </select>
                        </label>
                    </div>
                @endif
            </div>

            <span class="small muted">Messages sent more than 24 hours after the client’s last reply must use a template Meta has approved.</span>
            <div class="row" style="justify-content:flex-end">
                <button class="btn" type="submit" form="test-form">Send test to my phone</button>
                <button class="btn btn-primary" type="submit">Save changes</button>
            </div>
        </form>
        <form id="test-form" method="post" action="{{ route('templates.test', $current) }}" hidden>@csrf<input type="hidden" name="lang" value="{{ $lang }}" id="test-lang"></form>

        <aside class="stack-s" aria-label="Preview" style="gap:12px">
            <span class="small strong" style="color:var(--ink-2)">Preview with a sample booking</span>
            <div style="border-radius:24px;background:var(--chat);border:1px solid var(--line);overflow:hidden">
                <div class="row" style="height:56px;background:var(--dark);color:#FFF;padding:0 14px;gap:10px">
                    <span class="avatar" style="width:32px;height:32px;background:var(--mint);color:#0B1F18;font-family:var(--display);font-weight:700">{{ mb_substr(config('safara.brand'), 0, 1) }}</span>
                    <span class="strong">{{ config('safara.brand') }}</span>
                </div>
                <div class="chat" style="border-radius:0;min-height:320px">
                    <div class="bubble out" style="max-width:90%">
                        <div class="b-body" id="pv-body">{{ $preview }}</div>
                        <div id="pv-buttons">@foreach ($previewButtons as $b)<span class="b-btn">{{ $b }}</span>@endforeach</div>
                    </div>
                </div>
            </div>
        </aside>
    @endif
</div>
@endsection

@push('scripts')
<script>
(() => {
    const sample = @json(\App\Http\Controllers\TemplatesController::SAMPLE + ['brand' => config('safara.brand')]);
    const fill = (t) => t.replace(/\{(\w+)\}/g, (m, k) => (k in sample ? sample[k] : ''));
    let lang = @json($lang);
    const render = () => {
        const panel = document.querySelector(`[data-lang-panel="${lang}"]`);
        if (!panel) return;
        document.getElementById('pv-body').textContent = fill(panel.querySelector('[data-body]').value);
        const wrap = document.getElementById('pv-buttons');
        wrap.innerHTML = '';
        panel.querySelectorAll('[data-button]').forEach((b) => {
            if (!b.value.trim()) return;
            const s = document.createElement('span');
            s.className = 'b-btn';
            s.textContent = fill(b.value);
            wrap.appendChild(s);
        });
    };
    document.querySelectorAll('[data-lang]').forEach((btn) => btn.addEventListener('click', () => {
        lang = btn.dataset.lang;
        document.querySelectorAll('[data-lang]').forEach((b) => b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'));
        document.querySelectorAll('[data-lang-panel]').forEach((p) => { p.hidden = p.dataset.langPanel !== lang; });
        document.getElementById('lang-input').value = lang;
        document.getElementById('test-lang').value = lang;
        render();
    }));
    document.getElementById('tpl-form')?.addEventListener('input', render);
})();
</script>
@endpush
