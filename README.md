# Safara — WhatsApp flight desk

Clients book flights entirely on WhatsApp. They send a trip request and a photo of their passport, confirm the details, and pay on a link. The ticket is issued only after they pay, and the e-ticket comes back in the same chat. Operators watch everything on a web desk and step in when a person is needed.

```
Client on WhatsApp                        Safara                                   Outside services
──────────────────                        ──────                                   ────────────────
"Kano to Jeddah, 12 Oct, just me"  ──▶  understands the trip (rules or Claude)
passport photo                      ──▶  reads it (Claude vision + MRZ check digits) ──▶ Anthropic API
taps "Yes, correct"                 ──▶  searches fares, adds your markup         ──▶ Duffel
gets quote + pay link               ◀──  opens a pay link held for 2 hours
pays                                ──▶  webhook confirms payment                 ◀── your payment provider
                                          re-checks the live fare:
                                            fits / small rise → books it          ──▶ Duffel (order, paid from balance)
                                            big rise → waits for you (Fare review)
gets e-ticket PDF                   ◀──  sends booking ref + PDF
```

Built with Laravel 13, and runs on MySQL or SQLite. There's no front-end build step: the desk is Blade plus one CSS file.

## Screens

**Desk (operators, at `/desk`)**
- **Desk:** today's pipeline in five stages, with counts, flags and a route filter.
- **Bookings:** every booking, with status tabs, search, filters and CSV export.
- **Booking detail:**
  - the passport as read, with a confidence score per field, the original photo and a correction form
  - fare check and live offers, with "Issue ticket"
  - payment status and the timeline
- **Fare review:** when the fare rose after payment, you can let the client choose, absorb the difference, ask for it, or refund. It previews the WhatsApp message the client will get.
- **Chat:** the full WhatsApp thread. You can take over, which pauses the bot, and reply by hand.
- **Clients:** profiles, trips, and passports on file (encrypted), with a "delete passport data" button.
- **Payments:** money collected today, open links and refunds due, with retry, "mark as refunded" and resend.
- **Message templates:** every bot message in English and Hausa, with a live preview and fields for Meta approval.
- **Settings:**
  - markup, minimum margin and rounding
  - price hold time and payment methods
  - auto-ticketing, the absorb limit and the overnight pause
  - how long passport data is kept, and exchange rates
- **Simulator (`/dev/simulator`):** play the client yourself and rehearse every path, including blurry photos, fare rises, expired holds and no flights found. Disabled in production.

**Client pages** (opened from WhatsApp): pay link → provider checkout → payment confirmed, plus an expired-hold page with "Get a new price".

## Quick start (everything faked, nothing leaves your machine)

Requires PHP 8.3+ and Composer.

```bash
git clone https://github.com/Ahmadbaba46/safara.git && cd safara
composer run setup          # installs, creates .env + SQLite db, migrates, seeds templates and your login
php artisan db:seed --class=DemoSeeder   # optional: sample bookings so the desk isn't empty
php artisan serve
```

Log in at http://localhost:8000 with `SAFARA_ADMIN_EMAIL` / `SAFARA_ADMIN_PASSWORD` from `.env` (defaults: `admin@example.com` / `change-me`). Then open **Simulator** in the sidebar and type:

> Salam, I need a flight Kano to Jeddah, 12 October, just me.

Run the tests with `php artisan test`. GitHub Actions also runs them on every push.

## Going live, one connection at a time

Each outside service has a driver in `.env`. Switch them one at a time and keep the others on `fake`.

### 1. WhatsApp Cloud API
1. In Meta's developer app, open WhatsApp → Configuration. Set the webhook URL to `https://YOUR-DOMAIN/webhooks/whatsapp`, choose a verify token, and subscribe to **messages**.
2. Set these in `.env`:
   ```
   SAFARA_WHATSAPP_DRIVER=meta
   META_WHATSAPP_TOKEN=...            # a permanent system-user token
   META_WHATSAPP_PHONE_NUMBER_ID=...
   META_WHATSAPP_VERIFY_TOKEN=...     # the one you chose above
   META_APP_SECRET=...                # webhooks are rejected without a valid signature
   META_WHATSAPP_BUSINESS_NUMBER=234...
   SAFARA_OPERATOR_PHONE=234...       # where fare-review and hand-over alerts go
   ```
3. Messages sent more than 24 hours after a client's last message must be approved Meta templates. Submit these five for approval: **Price hold expired**, **Fare rose after payment**, **Pay the difference**, **Refund sent** and **Manual quote start**. Once each one is approved, enter its Meta name on the Message templates screen and set its status to Approved. Everything else is a normal reply inside the 24-hour window.

### 2. Passport reading and trip understanding (Anthropic)
```
SAFARA_PASSPORT_DRIVER=anthropic
SAFARA_TRIP_PARSER=anthropic        # optional; "rules" handles most messages for free
ANTHROPIC_API_KEY=...
```
Claude reads the photo page. The machine-readable lines (MRZ) are then checked with their check digits, and any field whose check digit passes replaces what was read from the printed text. Fields read with low confidence are flagged on the booking page, and the client always confirms before a quote is sent.

### 3. Duffel
```
SAFARA_FLIGHTS_DRIVER=duffel
DUFFEL_ACCESS_TOKEN=duffel_test_...  # start with a test token
DUFFEL_CONTACT_EMAIL=bookings@yourdomain
```
Orders are `instant` and paid from your Duffel balance, so keep it topped up. If Duffel prices an offer in USD, GBP or EUR, it's converted with the rates under **Settings → Pricing**.

### 4. Payments (pluggable)
You haven't chosen a provider yet, so this uses a test gateway. Its checkout page has a "Simulate successful payment" button, and it refuses to run in production. To add Paystack, Flutterwave, Monnify or another provider, implement `App\Contracts\PaymentGateway`. It has five methods (`checkout`, `verify`, `webhook`, `refund`, plus names) and is documented in the interface. Then set:
```
SAFARA_PAYMENTS_DRIVER="App\Integrations\Payments\PaystackGateway"
```
Point the provider's webhook at `https://YOUR-DOMAIN/webhooks/payments`, and pass `$payment->reference` as the transaction reference. The webhook is treated as the source of truth, and confirming the same payment twice is harmless.

## Production checklist
- `APP_ENV=production`, `APP_DEBUG=false`, HTTPS. The simulator and test payments switch off automatically.
- MySQL: set `DB_CONNECTION=mysql` and the `DB_*` variables, then `php artisan migrate --seed`.
- Queue: set `QUEUE_CONNECTION=database` and keep `php artisan queue:work` running (Supervisor or systemd). Webhooks return straight away and the work runs on the queue.
- Scheduler, run every minute: `* * * * * cd /path/to/safara && php artisan schedule:run`. It expires price holds, releases bookings held by the overnight pause, and deletes passport data that's past its keep-until date.
- Back up `storage/app/private`, which holds the encrypted passport photos and the e-tickets, along with the database. **Keep `APP_KEY` safe: without it the passport data can't be decrypted.**

## How it decides

**Price** = fare + the larger of (markup %) and (minimum margin), rounded up. With the defaults (6%, ₦10,000, round to ₦1,000), a fare of ₦646,200 is quoted at ₦685,000.

**After payment**, the fare is checked again:

| Live fare vs. what the client paid | Auto-issue on | Auto-issue off, or overnight pause |
|---|---|---|
| At or below | Ticket issued | Waits for you ("ready to ticket") |
| Above, by no more than the absorb limit (if absorbing is on) | Ticket issued, margin absorbs it | Waits for you |
| Above, by more than the limit | **Fare review**: you decide | **Fare review** |

In a fare review, you can:
- **Let the client choose.** They get buttons: pay the difference, fly on a nearby date that fits what they paid (found automatically), or take a full refund.
- **Absorb** the difference.
- **Ask for the difference** with a new pay link.
- **Refund** in full.

## Where things live

```
app/
  Contracts/        WhatsAppClient, PassportReader, TripParser, FlightSearch, PaymentGateway
  Integrations/     Meta, Anthropic, Duffel drivers + a Fake* driver for each
  Services/
    Conversation.php     the chat state machine (one inbound message → the right reply)
    QuoteService.php     search → price → pay link → quote
    TicketingService.php after-payment fare check, issuing, fare-review actions
    PaymentService.php   pay links, idempotent confirmation, expiry, refunds
    PassportService.php  encrypted storage, corrections, retention
    Messenger.php        templates, language, 24-hour window, message log
    TicketPdf.php        the e-ticket (no PDF package needed)
  Support/          pure logic: MRZ check digits, pricing, fare rules, trip parsing, PDF writer
resources/views/    desk, pay pages, simulator
database/seeders/   TemplateSeeder (all bot messages, EN + HA), DemoSeeder
```

## Privacy
- Passport names, numbers and dates of birth are encrypted in the database. Photos are encrypted on disk and served only to logged-in operators.
- Clients are asked before a passport is kept for their next booking. Retention is set in Settings, and deletion runs automatically.
- Operators can delete a client's passport data at any time from the Clients screen.

## Status of this code, honestly
- It was written in a sandbox that couldn't install Composer packages. Every PHP file was syntax-checked there. The pure logic was run and passed its checks: MRZ check digits, pricing, fare decisions, trip parsing and the PDF writer.
- The Laravel parts (feature tests, views, database) were **not** run before this was pushed. The first real run is `composer run setup && php artisan test`, locally or in GitHub Actions. Expect a small fix or two on that first run.
- The Hausa messages were drafted by machine. Have a fluent speaker review them on the Message templates screen before going live.
- Not handled yet:
  - multi-city trips
  - seat or extra-bag purchase
  - infants on a parent's lap (Duffel needs special handling)
  - changes or cancellations after ticketing ("CHANGE" hands the chat to a person)
