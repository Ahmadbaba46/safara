<?php

namespace App\Services;

use App\Contracts\TripParser;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Message;
use App\Models\Passport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The WhatsApp conversation: one inbound message in, the right reply out.
 * Each booking's status says where the conversation is; small details
 * (which field is being fixed, photo attempts) live in booking->state.
 */
class Conversation
{
    private const YES = ['yes', 'y', 'yeah', 'yep', 'yes correct', 'yes, correct', 'correct', 'ok', 'okay', 'eh', 'ee', 'i', 'haka ne', 'daidai', 'daidai ne', 'eh daidai ne', 'eh, daidai ne'];

    private const NO = ['no', 'n', 'nope', 'wrong', 'fix', 'fix something', "a'a", 'aa', 'ba daidai ba', 'gyara'];

    public function __construct(
        private Messenger $messenger,
        private TripParser $parser,
        private PassportService $passports,
        private QuoteService $quotes,
        private TicketingService $ticketing,
        private PaymentService $payments,
    ) {}

    public function handle(InboundMessage $in): ?Booking
    {
        if ($in->waId && Message::query()->where('wa_id', $in->waId)->exists()) {
            return null; // WhatsApp retried a webhook we already handled.
        }

        $client = Client::query()->firstOrCreate(['phone' => $in->from], ['name' => $in->profileName]);
        if (! $client->name && $in->profileName) {
            $client->name = $in->profileName;
        }
        $client->last_inbound_at = now();
        $client->save();

        $booking = Booking::currentFor($client);
        $message = $client->messages()->create([
            'booking_id' => $booking?->id,
            'direction' => 'in',
            'type' => $in->type === 'reply' ? 'button' : $in->type,
            'body' => $in->type === 'image' ? ($in->text ?: 'Photo') : $in->text,
            'payload' => array_filter(['reply_id' => $in->replyId, 'media_id' => $in->mediaId, 'mime' => $in->mime]),
            'wa_id' => $in->waId,
        ]);

        try {
            return $this->route($client, $booking, $in, $message);
        } catch (Throwable $e) {
            Log::error('Conversation failed for '.$client->phone.': '.$e->getMessage(), ['exception' => $e]);
            $booking ??= Booking::currentFor($client);
            if ($booking) {
                $booking->setFlag('Bot error · please reply by hand', 'warn')->save();
                $booking->event('error', 'Bot error', mb_substr($e->getMessage(), 0, 190));
            }
            $this->messenger->toOperator('Safara bot error for +'.$client->phone.': '.mb_substr($e->getMessage(), 0, 150));

            return $booking;
        }
    }

    private function route(Client $client, ?Booking $booking, InboundMessage $in, Message $message): ?Booking
    {
        $words = $in->words();

        // ---- things that work at any point --------------------------------
        if (in_array($words, ['help', 'taimako', 'agent', 'human', 'person'], true)) {
            return $this->handover($client, $booking, 'Asked for help');
        }
        if (in_array($words, ['change', 'canji', 'canza'], true)) {
            return $this->handover($client, $booking ?? $client->bookings()->first(), 'Asked to change a booking', 'change_request');
        }
        if (in_array($words, ['english', 'turanci'], true) || in_array($words, ['hausa'], true)) {
            $client->update(['language' => $words === 'hausa' ? 'ha' : 'en']);
            $this->messenger->say($client, $booking, 'language_set');

            return $booking;
        }
        if (in_array($in->replyId, ['save:yes', 'save:no'], true)) {
            $this->saveConsent($client, $in->replyId === 'save:yes');

            return $booking;
        }

        if ($booking?->bot_paused) {
            $booking->setFlag('New message from client', 'info')->save();

            return $booking;
        }

        // ---- a new conversation ------------------------------------------------
        if (! $booking) {
            $trip = $in->type === 'text' ? $this->parse((string) $in->text) : null;
            $recent = $client->bookings()->where('status', BookingStatus::Ticketed)->where('ticketed_at', '>', now()->subHours(6))->exists();
            if ($recent && ($trip === null || $trip->isEmpty()) && $in->type !== 'image') {
                $this->messenger->say($client, null, 'youre_welcome');

                return null;
            }

            $booking = DB::transaction(fn () => $client->bookings()->create([
                'status' => BookingStatus::CollectingTrip,
                'source' => 'whatsapp',
            ]));
            $message->update(['booking_id' => $booking->id]);
            $booking->event('chat', 'Client started chat', $in->text ? '“'.mb_strimwidth($in->text, 0, 120, '…').'”' : null);

            if ($trip?->language && $client->wasRecentlyCreated) {
                $client->update(['language' => $trip->language]);
            }
        }

        match ($booking->status) {
            BookingStatus::CollectingTrip => $this->collectTrip($booking, $in),
            BookingStatus::AwaitingPassport => $this->awaitPassport($booking, $in),
            BookingStatus::Confirming => $this->confirming($booking, $in),
            BookingStatus::AwaitingPayment => $this->awaitingPayment($booking, $in),
            BookingStatus::Expired => $this->expired($booking, $in),
            BookingStatus::AwaitingChoice => $this->awaitingChoice($booking, $in),
            default => $this->messenger->say($client, $booking, 'paid_wait'),
        };

        return $booking->fresh();
    }

    // ---- trip -------------------------------------------------------------

    private function collectTrip(Booking $booking, InboundMessage $in): void
    {
        $client = $booking->client;
        if ($in->type !== 'text') {
            $this->messenger->say($client, $booking, 'ask_trip');

            return;
        }

        $req = $this->parse((string) $in->text);
        $first = $booking->origin === null && $booking->destination === null && $booking->depart_on === null && $booking->travellers === null;
        $this->applyTrip($booking, $req->mergeInto([
            'origin' => $booking->origin, 'destination' => $booking->destination,
            'depart_on' => $booking->depart_on?->toDateString(), 'return_on' => $booking->return_on?->toDateString(),
            'travellers' => $booking->travellers,
        ]));

        if ($first && $req->isEmpty()) {
            $this->messenger->say($client, $booking, 'ask_trip');

            return;
        }
        if ($this->askForMissing($booking)) {
            return;
        }

        $booking->event('trip', 'Trip understood', $booking->routeCodes().' · '.$booking->dateLabel().' · '.$booking->travellersLabel());

        // Came back with a new date after "no flights found"? Their details are already confirmed.
        $confirmed = $booking->passports()->whereNotNull('confirmed_at')->count();
        if ($confirmed >= (int) $booking->travellers) {
            $this->startQuote($booking);

            return;
        }

        $this->startPassports($booking);
    }

    private function applyTrip(Booking $booking, array $trip): void
    {
        if ($trip['origin'] && $trip['origin'] === $trip['destination']) {
            $trip['destination'] = null;
        }
        $booking->fill([
            'origin' => $trip['origin'],
            'destination' => $trip['destination'],
            'depart_on' => $trip['depart_on'],
            'return_on' => $trip['return_on'] && $trip['depart_on'] && $trip['return_on'] >= $trip['depart_on'] ? $trip['return_on'] : null,
            'travellers' => $trip['travellers'],
        ])->save();
    }

    /** Ask for the first missing piece of the trip. Returns true if we asked. */
    private function askForMissing(Booking $booking): bool
    {
        $missing = match (true) {
            ! $booking->destination => 'ask_destination',
            ! $booking->origin => 'ask_origin',
            ! $booking->depart_on => 'ask_date',
            ! $booking->travellers => 'ask_travellers',
            default => null,
        };
        if (! $missing) {
            return false;
        }
        $this->messenger->say($booking->client, $booking, $missing, [
            'destination' => $booking->destination ? \App\Support\Iata::city($booking->destination) : '',
            'origin' => $booking->origin ? \App\Support\Iata::city($booking->origin) : '',
        ]);

        return true;
    }

    // ---- passports -----------------------------------------------------------

    private function startPassports(Booking $booking): void
    {
        $booking->update(['status' => BookingStatus::AwaitingPassport]);
        $client = $booking->client;
        $saved = $client->savedPassport;

        if ($saved && (int) $booking->travellers === 1 && $booking->passports()->count() === 0) {
            $this->messenger->say($client, $booking, 'saved_passport', [
                'name' => $saved->firstName(),
                'number' => $saved->maskedNumber(),
                'expiry' => $saved->expiry?->format('d M Y'),
            ], ['passport:saved', 'passport:new']);

            return;
        }

        $key = (int) $booking->travellers > 1 ? 'ask_passport_group' : 'ask_passport';
        $this->messenger->say($client, $booking, $key, ['total' => (int) $booking->travellers]);
    }

    private function awaitPassport(Booking $booking, InboundMessage $in): void
    {
        $client = $booking->client;

        if ($in->replyId === 'passport:saved' && $client->savedPassport) {
            $this->passports->attachSaved($booking, $client->savedPassport);
            $booking->event('passport', 'Used saved passport', $client->savedPassport->maskedNumber());
            $this->afterPassportAdded($booking);

            return;
        }
        if ($in->replyId === 'passport:new') {
            $this->messenger->say($client, $booking, 'ask_passport', ['total' => (int) $booking->travellers]);

            return;
        }
        if ($in->type === 'image' && $in->mediaId) {
            $this->readPassport($booking, $in);

            return;
        }

        $this->messenger->say($client, $booking, 'ask_passport_again');
    }

    private function readPassport(Booking $booking, InboundMessage $in): void
    {
        $client = $booking->client;
        $booking->setFlag('Reading passport…', 'info')->save();

        try {
            [$reading, $path] = $this->passports->readFromWhatsApp($in->mediaId, $in->mime);
        } catch (Throwable $e) {
            Log::error("Passport read failed for {$booking->reference}: ".$e->getMessage());
            $booking->setFlag('Passport reader failed · check it', 'warn')->save();
            $booking->event('error', 'Could not read passport photo', mb_substr($e->getMessage(), 0, 190));
            $this->messenger->say($client, $booking, 'photo_unclear', ['field' => $client->language === 'ha' ? 'bayanan fasfo' : 'passport details']);

            return;
        }

        if (! $reading->usable()) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($path);
            $attempts = (int) $booking->stateGet('photo_attempts', 0) + 1;
            $booking->stateSet('photo_attempts', $attempts)->save();
            $booking->event('passport', 'Photo unclear', 'Could not read the '.$reading->unreadableLabel());

            if ($attempts >= config('safara.max_photo_attempts')) {
                $this->handover($client, $booking, 'Photo unclear '.$attempts.' times');

                return;
            }

            $booking->setFlag('Blurry photo · asked to resend', 'warn')->save();
            $this->messenger->say($client, $booking, 'photo_unclear', ['field' => $this->fieldLabel($reading->unreadableLabel(), $client->language)]);

            return;
        }

        $passport = $this->passports->createFromReading($booking, $reading, $path);
        $booking->stateSet('photo_attempts', 0)->setFlag(null)->save();
        $readCount = count(array_filter($reading->fields));
        $booking->event('passport', 'Passport photo received', $readCount.' fields read · '.($reading->mrzValid ? 'MRZ passed' : 'MRZ not verified'));

        // First traveller on a first booking: use their passport name instead of the WhatsApp profile name.
        if ($booking->passports()->count() === 1 && ! $client->passports()->whereNotNull('confirmed_at')->exists()) {
            $client->update(['name' => mb_convert_case(strtolower($passport->fullName()), MB_CASE_TITLE)]);
        }

        if ($this->passports->expiresTooSoon($passport, $booking)) {
            $booking->setFlag('Passport expires soon after the trip', 'warn')->save();
            $this->messenger->say($client, $booking, 'passport_expiring', [
                'expiry' => $passport->expiry->format('d M Y'),
                'months' => config('safara.passport_validity_months'),
            ]);
        }

        $this->afterPassportAdded($booking);
    }

    private function afterPassportAdded(Booking $booking): void
    {
        $count = $booking->passports()->count();
        $total = max(1, (int) $booking->travellers);

        if ($count < $total) {
            $this->messenger->say($booking->client, $booking, 'ask_passport_next', ['n' => $count + 1, 'total' => $total]);

            return;
        }

        $booking->update(['status' => BookingStatus::Confirming]);
        $this->sendDetails($booking, 'confirm_details');
    }

    private function sendDetails(Booking $booking, string $key, array $extra = []): void
    {
        $client = $booking->client;
        $this->messenger->say($client, $booking, $key, $extra + [
            'name' => $booking->passports()->first()?->firstName() ?: '',
            'details' => $this->passports->details($booking, $client->language ?: 'en'),
        ], ['confirm:yes', 'confirm:fix']);
    }

    // ---- confirming and fixing ----------------------------------------------

    private function confirming(Booking $booking, InboundMessage $in): void
    {
        $client = $booking->client;
        $words = $in->words();
        $fixField = $booking->stateGet('fix.field');

        if ($in->replyId === 'confirm:yes' || (! $fixField && ! $in->replyId && in_array($words, self::YES, true))) {
            \App\Models\Passport::query()->whereIn('id', $booking->passports()->pluck('passports.id'))->update(['confirmed_at' => now()]);
            $booking->stateForget('fix')->save();
            $booking->event('confirmed', 'Client confirmed details', $in->replyId ? 'Tapped “'.($in->text ?: 'Yes, correct').'”' : '“'.$in->text.'”');
            $this->startQuote($booking);

            return;
        }

        if ($in->replyId === 'confirm:fix' || (! $fixField && ! $in->replyId && in_array($words, self::NO, true))) {
            $booking->stateForget('fix')->save();
            $this->askWhichToFix($booking);

            return;
        }

        if ($in->replyId && str_starts_with($in->replyId, 'fix:traveller:')) {
            $booking->stateSet('fix.traveller', (int) substr($in->replyId, 14))->save();
            $this->askFieldToFix($booking);

            return;
        }

        if ($in->replyId && str_starts_with($in->replyId, 'fix:')) {
            $field = substr($in->replyId, 4);
            if (! in_array($field, ['name', 'dob', 'number', 'expiry', 'trip'], true)) {
                $this->askWhichToFix($booking);

                return;
            }
            $booking->stateSet('fix.field', $field)->save();
            $this->messenger->say($client, $booking, 'fix_prompt_'.$field);

            return;
        }

        if ($fixField && $in->type === 'text') {
            $this->applyFix($booking, $fixField, (string) $in->text);

            return;
        }

        $this->sendDetails($booking, 'confirm_details');
    }

    private function askWhichToFix(Booking $booking): void
    {
        $client = $booking->client;
        $ha = $client->language === 'ha';
        $passports = $booking->passports()->get();

        if ($passports->count() > 1) {
            $rows = [];
            foreach ($passports as $i => $p) {
                $rows['fix:traveller:'.($i + 1)] = mb_strimwidth($p->fullName(), 0, 24, '');
            }
            $rows['fix:trip'] = $ha ? 'Bayanin tafiya' : 'Trip details';
            $this->messenger->list($client, $booking, 'fix_which_traveller', [], $ha ? 'Zaɓa' : 'Choose', array_slice($rows, 0, 10, true));

            return;
        }

        $booking->stateSet('fix.traveller', 1)->save();
        $this->askFieldToFix($booking);
    }

    private function askFieldToFix(Booking $booking): void
    {
        $ha = $booking->client->language === 'ha';
        $rows = $ha
            ? ['fix:name' => 'Suna', 'fix:dob' => 'Ranar haihuwa', 'fix:number' => 'Lambar fasfo', 'fix:expiry' => 'Ranar ƙarewa', 'fix:trip' => 'Bayanin tafiya']
            : ['fix:name' => 'Name', 'fix:dob' => 'Date of birth', 'fix:number' => 'Passport number', 'fix:expiry' => 'Expiry date', 'fix:trip' => 'Trip details'];
        $this->messenger->list($booking->client, $booking, 'fix_which', [], $ha ? 'Zaɓa' : 'Choose', $rows);
    }

    private function applyFix(Booking $booking, string $field, string $text): void
    {
        $client = $booking->client;

        if ($field === 'trip') {
            $req = $this->parse($text);
            if ($req->isEmpty()) {
                $this->messenger->say($client, $booking, 'fix_invalid', ['hint' => $this->messenger->render('fix_prompt_trip', [], $client->language)]);

                return;
            }
            $before = (int) $booking->travellers;
            $this->applyTrip($booking, $req->mergeInto([
                'origin' => $booking->origin, 'destination' => $booking->destination,
                'depart_on' => $booking->depart_on?->toDateString(), 'return_on' => $booking->return_on?->toDateString(),
                'travellers' => $booking->travellers,
            ]));
            $booking->stateForget('fix')->save();
            $booking->event('fix', 'Client changed the trip', $booking->routeCodes().' · '.$booking->dateLabel().' · '.$booking->travellersLabel());

            if ($this->askForMissing($booking)) {
                $booking->update(['status' => BookingStatus::CollectingTrip]);

                return;
            }
            $after = (int) $booking->travellers;
            if ($after < $before) {
                $extra = $booking->passports()->wherePivot('position', '>', $after)->pluck('passports.id');
                $booking->passports()->detach($extra);
            }
            if ($after > $booking->passports()->count()) {
                $booking->update(['status' => BookingStatus::AwaitingPassport]);
                $this->messenger->say($client, $booking, 'ask_passport_next', ['n' => $booking->passports()->count() + 1, 'total' => $after]);

                return;
            }
            $this->sendDetails($booking, 'fixed', ['field' => $client->language === 'ha' ? 'Tafiya' : 'Trip', 'value' => $booking->routeCodes().' · '.$booking->dateLabel()]);

            return;
        }

        $position = (int) $booking->stateGet('fix.traveller', 1);
        /** @var Passport|null $passport */
        $passport = $booking->passports()->wherePivot('position', $position)->first() ?? $booking->passports()->first();
        $result = $passport ? $this->passports->applyFix($passport, $field, $text) : null;

        if (! $result) {
            $this->messenger->say($client, $booking, 'fix_invalid', ['hint' => $this->messenger->render('fix_prompt_'.$field, [], $client->language)]);

            return;
        }

        [$label, $value] = $result;
        $booking->stateForget('fix')->setFlag('Client corrected '.strtolower($label), 'mute')->save();
        $booking->event('fix', 'Client corrected '.strtolower($label), $value);
        $this->sendDetails($booking, 'fixed', ['field' => $this->fieldLabel($label, $client->language), 'value' => $value]);
    }

    // ---- quote and payment ----------------------------------------------------

    private function startQuote(Booking $booking): void
    {
        $this->messenger->say($booking->client, $booking, 'searching', ['name' => $booking->passports()->first()?->firstName() ?: '']);
        $this->quotes->quote($booking);
    }

    private function awaitingPayment(Booking $booking, InboundMessage $in): void
    {
        $payment = $booking->openPayment();
        if (! $payment || ! $payment->isOpen()) {
            $this->payments->expireHolds();
            $this->expired($booking->fresh(), $in);

            return;
        }

        if ($this->maybeNewDate($booking, $in)) {
            $this->startQuote($booking);

            return;
        }

        $this->quotes->remind($booking, $payment);
    }

    private function expired(Booking $booking, InboundMessage $in): void
    {
        $this->maybeNewDate($booking, $in);
        $this->quotes->requote($booking);
    }

    /** The client typed a different travel date: use it. */
    private function maybeNewDate(Booking $booking, InboundMessage $in): bool
    {
        if ($in->type !== 'text') {
            return false;
        }
        $req = $this->parse((string) $in->text);
        if (! $req->departOn || $req->departOn === $booking->depart_on?->toDateString()) {
            return false;
        }
        $booking->update(['depart_on' => $req->departOn, 'return_on' => $req->returnOn ?? $booking->return_on]);
        $booking->event('trip', 'Client changed the date', $booking->dateLabel());

        return true;
    }

    private function awaitingChoice(Booking $booking, InboundMessage $in): void
    {
        $id = (string) $in->replyId;

        if ($id === 'fare:diff') {
            $this->ticketing->askDifference($booking);

            return;
        }
        if (str_starts_with($id, 'fare:alt:')) {
            $alt = $booking->offers()->find((int) substr($id, 9));
            if ($alt) {
                $booking->event('review', 'Client chose another date', $alt->depart_on->format('D j M'));
                $this->ticketing->moveToAlternative($booking, $alt);

                return;
            }
        }
        if ($id === 'fare:refund') {
            $booking->event('review', 'Client chose a refund');
            $this->ticketing->refundFull($booking, 'Client chose a refund after the fare rose');

            return;
        }

        $this->messenger->say($booking->client, $booking, 'choose_option');
    }

    // ---- misc --------------------------------------------------------------

    private function handover(Client $client, ?Booking $booking, string $reason, string $key = 'handover'): ?Booking
    {
        $booking ??= $client->bookings()->create(['status' => BookingStatus::CollectingTrip, 'source' => 'whatsapp']);
        $booking->update(['bot_paused' => true]);
        $booking->setFlag($reason.' · reply by hand', 'warn')->save();
        $booking->event('handover', $reason, 'Bot paused until someone resumes it');
        $this->messenger->say($client, $booking, $key);
        $this->messenger->toOperator("{$booking->reference} (+{$client->phone}): $reason. ".route('bookings.chat', $booking));

        return $booking;
    }

    private function saveConsent(Client $client, bool $yes): void
    {
        $booking = $client->bookings()->where('status', BookingStatus::Ticketed)->first();
        $passports = $booking ? $booking->passports()->whereNull('consent_at')->get() : collect();

        foreach ($passports as $p) {
            if ($yes) {
                $p->update(['consent_at' => now(), 'delete_after' => $p->expiry?->copy()->endOfDay()]);
            }
        }
        if ($booking) {
            $booking->event('consent', $yes ? 'Client agreed to keep passport details' : 'Client declined keeping passport details');
        }

        $this->messenger->say($client, $booking, $yes ? 'save_passport_yes' : 'save_passport_no');
    }

    /** @var array<string, \App\Support\TripRequest> parsed once per message */
    private array $parsed = [];

    private function parse(string $text): \App\Support\TripRequest
    {
        return $this->parsed[$text] ??= $this->parser->parse($text, now()->toDateTimeImmutable());
    }

    private function fieldLabel(string $english, ?string $lang): string
    {
        if ($lang !== 'ha') {
            return $english;
        }

        return [
            'passport number' => 'lambar fasfo', 'expiry date' => 'ranar ƙarewa', 'date of birth' => 'ranar haihuwa',
            'surname' => 'sunan iyali', 'given names' => 'suna', 'passport details' => 'bayanan fasfo', 'nationality' => 'ƙasa',
            'Name' => 'Suna', 'Date of birth' => 'Ranar haihuwa', 'Passport number' => 'Lambar fasfo', 'Expiry date' => 'Ranar ƙarewa',
        ][$english] ?? $english;
    }
}
