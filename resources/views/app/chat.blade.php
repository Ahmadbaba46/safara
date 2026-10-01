<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#13241F">
    <meta name="robots" content="noindex">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ \Illuminate\Support\Str::before($brand, ' ') }}">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="icon" href="{{ asset('icons/icon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('icons/icon-192.png') }}">
    <title>{{ $brand }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=1">
</head>
<body>
<div class="shell">
    <header class="bar">
        <span class="mark" aria-hidden="true">{{ mb_substr($brand, 0, 1) }}</span>
        <div class="who">
            <strong>{{ $brand }}</strong>
            <span id="sub">Flights, ticketed in minutes</span>
        </div>
        <div class="menu-wrap">
            <button class="icon-btn" id="menu-btn" type="button" aria-label="Menu" aria-expanded="false" aria-controls="menu">⋮</button>
            <div class="menu" id="menu" hidden>
                <button type="button" id="forget">Delete my data and start over</button>
            </div>
        </div>
    </header>

    <div class="trip" id="trip" hidden></div>

    {{-- Welcome: shown until the device has a profile. --}}
    <main class="welcome" id="welcome" @if($client) hidden @endif>
        <div class="welcome-card">
            <span class="mark big" aria-hidden="true">{{ mb_substr($brand, 0, 1) }}</span>
            <h1>Book your flight in a chat</h1>
            <p>Tell us where you’re going, send a photo of your passport, and pay securely. Your e-ticket comes back right here.</p>
            <form id="start-form" novalidate>
                <label>Your name<input name="name" autocomplete="name" maxlength="60" required placeholder="Aisha Bello"></label>
                <label>Phone number <span class="opt">(optional, for your ticket)</span><input name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="0803 000 0000"></label>
                <fieldset>
                    <legend>Chat in</legend>
                    <label class="chip-radio"><input type="radio" name="language" value="en" checked><span>English</span></label>
                    <label class="chip-radio"><input type="radio" name="language" value="ha"><span>Hausa</span></label>
                </fieldset>
                <p class="error" id="start-error" role="alert" hidden></p>
                <button class="primary" type="submit">Start chatting</button>
            </form>
            <p class="fine">We keep passport details encrypted, only for your booking, and you can delete everything from the menu at any time.</p>
        </div>
    </main>

    {{-- Chat --}}
    <main class="chat-view" id="chat-view" @if(! $client) hidden @endif>
        <div class="messages" id="messages" role="log" aria-live="polite" aria-label="Conversation"></div>
        <div class="empty" id="empty" hidden>
            <p>Say hello, or try one of these:</p>
            <div class="suggest">
                <button type="button" data-say="Salam, I need a flight Kano to Jeddah, 12 October, just me.">Kano to Jeddah, 12 Oct, just me</button>
                <button type="button" data-say="Lagos to London next month, 2 adults">Lagos to London, 2 adults</button>
                <button type="button" data-say="Abuja to Lagos tomorrow, return Friday">Abuja to Lagos, return Friday</button>
            </div>
        </div>
        <div class="paused" id="paused" hidden>An agent has joined this chat and will reply shortly.</div>
        <form class="composer" id="composer" autocomplete="off">
            <input type="file" id="photo" accept="image/*" hidden>
            <button class="icon-btn" id="attach" type="button" aria-label="Send a photo (passport)">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.5l-8.6 8.6a5.5 5.5 0 0 1-7.8-7.8l9-9a3.7 3.7 0 0 1 5.2 5.2l-9 9a1.8 1.8 0 0 1-2.6-2.6l8.3-8.3"/></svg>
            </button>
            <label class="sr-only" for="text">Message</label>
            <input id="text" name="text" placeholder="Message" maxlength="1000" enterkeyhint="send">
            <button class="send" type="submit" aria-label="Send">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M3.4 20.4l17.5-7.5a1 1 0 0 0 0-1.8L3.4 3.6a1 1 0 0 0-1.4 1.2L4 11l9 1-9 1-2 6.2a1 1 0 0 0 1.4 1.2z"/></svg>
            </button>
        </form>
    </main>
</div>
<div class="toast" id="toast" role="status" hidden></div>

<script>
(() => {
    const $ = (id) => document.getElementById(id);
    const csrf = document.querySelector('meta[name=csrf-token]').content;
    const urls = {
        start: @json(route('app.start')), messages: @json(route('app.messages')),
        send: @json(route('app.send')), forget: @json(route('app.forget')),
    };
    const state = { ready: @json((bool) $client), after: 0, lastDay: null, pending: false, timer: null, shown: new Set() };

    const toast = (text) => { const t = $('toast'); t.textContent = text; t.hidden = false; clearTimeout(toast.h); toast.h = setTimeout(() => t.hidden = true, 4000); };

    async function call(url, opts = {}) {
        const res = await fetch(url, { credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', ...(opts.headers || {}) }, ...opts });
        const raw = await res.text();
        let data = null;
        try { data = JSON.parse(raw); } catch (e) {}
        if (data === null || typeof data !== 'object') {
            // Not JSON (a proxy or PHP error page). Say what we got instead of crashing.
            const what = raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 140);
            const err = new Error('Unexpected reply from the server (HTTP ' + res.status + ')' + (what ? ': ' + what : '') + '.');
            err.status = res.status; throw err;
        }
        if (!res.ok) {
            const err = new Error((data && (data.message || Object.values(data.errors || {})[0]?.[0])) || 'Something went wrong. Please try again.');
            err.status = res.status; throw err;
        }
        return data;
    }

    // ---- rendering -----------------------------------------------------------
    const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };

    function bubble(m) {
        const b = el('div', 'bubble ' + (m.dir === 'in' ? 'in' : 'out') + (m.failed ? ' failed' : ''));
        b.dataset.id = m.id;
        if (m.type === 'image') b.append(el('div', 'photo', 'Passport photo'));
        if (m.file) {
            const a = el('a', 'doc'); a.href = m.file.url; a.target = '_blank'; a.rel = 'noopener';
            a.append(el('span', 'pdf', 'PDF'));
            const t = el('span', 'doc-t'); t.append(el('strong', null, m.file.name), el('small', null, 'E-ticket · tap to open'));
            a.append(t); b.append(a);
        }
        if (m.body) {
            const body = el('div', 'body', m.body);
            body.append(el('span', 'time', (m.from ? m.from + ' · ' : '') + m.time + (m.failed ? ' · not delivered' : '')));
            b.append(body);
        } else {
            b.append(el('span', 'time solo', m.time));
        }
        if (m.url) { const a = el('a', 'opt-btn', m.label || 'Open'); a.href = m.url; b.append(a); }
        const options = m.buttons.length ? m.buttons : m.rows;
        if (m.rows.length) b.append(el('div', 'opt-label', m.label || 'Choose'));
        options.forEach((o) => {
            if (m.live) {
                const btn = el('button', 'opt-btn' + (m.rows.length ? ' row' : ''), o.title); btn.type = 'button';
                btn.addEventListener('click', () => post({ kind: 'reply', reply_id: o.id, text: o.title }, o.title));
                b.append(btn);
            } else {
                b.append(el('span', 'opt-btn dim' + (m.rows.length ? ' row' : ''), o.title));
            }
        });
        return b;
    }

    function append(messages) {
        const box = $('messages');
        const nearBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 140;
        box.querySelectorAll('.bubble.optimistic').forEach((n) => n.remove());
        messages.forEach((m) => {
            if (state.shown.has(m.id)) return;
            state.shown.add(m.id);
            if (m.day !== state.lastDay) { box.append(el('span', 'day', m.day)); state.lastDay = m.day; }
            box.append(bubble(m));
            state.after = Math.max(state.after, m.id);
        });
        if (nearBottom || messages.length) box.scrollTop = box.scrollHeight;
        $('empty').hidden = state.shown.size > 0;
    }

    // Only the newest question keeps clickable options.
    function freeze(snapshot) {
        const live = snapshot.messages.filter((m) => m.live).map((m) => m.id);
        document.querySelectorAll('.bubble').forEach((b) => {
            const id = Number(b.dataset.id);
            if (!b.querySelector('button.opt-btn')) return;
            if (live.length ? !live.includes(id) : snapshot.messages.length && id < Math.max(...snapshot.messages.map((m) => m.id))) {
                b.querySelectorAll('button.opt-btn').forEach((btn) => { const s = el('span', btn.className + ' dim', btn.textContent); btn.replaceWith(s); });
            }
        });
    }

    function header(snapshot) {
        const trip = $('trip');
        if (snapshot.booking) {
            trip.hidden = false;
            trip.replaceChildren(el('strong', null, snapshot.booking.reference), el('span', 'grow', snapshot.booking.trip), el('span', 'status', snapshot.booking.status));
        }
        $('paused').hidden = !snapshot.paused;
    }

    function apply(snapshot) {
        if (!snapshot || !Array.isArray(snapshot.messages)) return;
        freeze(snapshot);
        append(snapshot.messages);
        header(snapshot);
    }

    // ---- sending -------------------------------------------------------------
    function typing(on) {
        const box = $('messages');
        box.querySelector('.typing')?.remove();
        if (on) { const t = el('div', 'typing'); t.append(el('i'), el('i'), el('i')); box.append(t); box.scrollTop = box.scrollHeight; }
    }

    async function post(payload, optimisticText) {
        if (state.pending) return;
        state.pending = true;
        $('composer').classList.add('busy');
        if (optimisticText) {
            const box = $('messages');
            const b = el('div', 'bubble in optimistic'); const body = el('div', 'body', optimisticText);
            body.append(el('span', 'time', '…')); b.append(body); box.append(b); box.scrollTop = box.scrollHeight;
            $('empty').hidden = true;
        }
        typing(true);
        const fd = new FormData();
        Object.entries({ ...payload, after: state.after }).forEach(([k, v]) => v != null && fd.append(k, v));
        try {
            apply(await call(urls.send, { method: 'POST', body: fd }));
        } catch (e) {
            document.querySelectorAll('.bubble.optimistic').forEach((n) => n.remove());
            toast(e.message);
            setTimeout(poll, 800);
        } finally {
            typing(false);
            state.pending = false;
            $('composer').classList.remove('busy');
        }
    }

    $('composer').addEventListener('submit', (e) => {
        e.preventDefault();
        const text = $('text').value.trim();
        if (!text) return;
        $('text').value = '';
        post({ kind: 'text', text }, text);
    });
    $('attach').addEventListener('click', () => $('photo').click());
    // Phone photos are several MB. Scale them down so the upload is fast and the model reads them sooner;
    // 2000px on the long side keeps passport text and the machine-readable lines sharp.
    async function shrink(file) {
        try {
            if (!file.type.startsWith('image/') || file.size < 900 * 1024) return file;
            const bmp = await createImageBitmap(file);
            const scale = Math.min(1, 2000 / Math.max(bmp.width, bmp.height));
            const c = document.createElement('canvas');
            c.width = Math.round(bmp.width * scale); c.height = Math.round(bmp.height * scale);
            c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
            const blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.9));
            return blob && blob.size < file.size ? new File([blob], 'passport.jpg', { type: 'image/jpeg' }) : file;
        } catch (err) { return file; }
    }
    $('photo').addEventListener('change', async (e) => {
        const f = e.target.files[0]; e.target.value = '';
        if (f) post({ kind: 'photo', photo: await shrink(f) }, 'Photo');
    });
    document.querySelectorAll('[data-say]').forEach((b) => b.addEventListener('click', () => post({ kind: 'text', text: b.dataset.say }, b.dataset.say)));

    // ---- polling (agent replies, ticket arriving after payment) ----------------
    async function poll() {
        if (!state.ready || state.pending || document.hidden) return;
        try { apply(await call(urls.messages + '?after=' + state.after)); } catch (e) { /* offline: try again next tick */ }
    }
    function schedule() { clearInterval(state.timer); state.timer = setInterval(poll, 3000); }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
    window.addEventListener('focus', poll);

    // ---- welcome, menu -------------------------------------------------------
    function enter() {
        state.ready = true;
        $('welcome').hidden = true; $('chat-view').hidden = false;
        schedule();
        call(urls.messages + '?after=0').then((s) => { apply(s); $('text').focus({ preventScroll: true }); }).catch(() => {});
    }

    $('start-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const err = $('start-error'); err.hidden = true;
        const fd = new FormData(e.target);
        if (!String(fd.get('name')).trim()) { err.textContent = 'Please tell us your name.'; err.hidden = false; return; }
        const btn = e.target.querySelector('button.primary'); btn.disabled = true;
        try { await call(urls.start, { method: 'POST', body: fd }); enter(); }
        catch (x) { err.textContent = x.message; err.hidden = false; }
        finally { btn.disabled = false; }
    });

    const menu = $('menu'), menuBtn = $('menu-btn');
    menuBtn.addEventListener('click', (e) => { e.stopPropagation(); menu.hidden = !menu.hidden; menuBtn.setAttribute('aria-expanded', String(!menu.hidden)); });
    document.addEventListener('click', () => { menu.hidden = true; menuBtn.setAttribute('aria-expanded', 'false'); });
    $('forget').addEventListener('click', async () => {
        if (!confirm('Delete your chat, bookings and passport details from this app? This cannot be undone.')) return;
        try { await call(urls.forget, { method: 'POST' }); location.reload(); } catch (e) { toast(e.message); }
    });

    if (state.ready) { enter(); }
    if ('serviceWorker' in navigator) navigator.serviceWorker.register(@json(asset('sw.js'))).catch(() => {});
})();
</script>
</body>
</html>
