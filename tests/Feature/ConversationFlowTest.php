<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Integrations\WhatsApp\FakeWhatsApp;
use App\Models\Passport;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Storage;

class ConversationFlowTest extends FlowTestCase
{
    public function test_happy_path_from_first_message_to_e_ticket(): void
    {
        $booking = $this->say('Salam. I need a flight Kano to Jeddah, 12 October, just me.');
        $this->assertSame(BookingStatus::AwaitingPassport, $booking->status);
        $this->assertSame(['KAN', 'JED', '2026-10-12', 1], [$booking->origin, $booking->destination, $booking->depart_on->toDateString(), $booking->travellers]);
        $this->assertStringContainsString('passport', $this->lastSent()['body']);

        $booking = $this->sendPhoto();
        $this->assertSame(BookingStatus::Confirming, $booking->status);
        $this->assertSame('buttons', $this->lastSent()['type']);
        $this->assertStringContainsString('AISHA BELLO', $this->lastSent()['body']);
        $this->assertSame(['confirm:yes', 'confirm:fix'], array_keys($this->lastSent()['buttons']));

        $booking = $this->tap('confirm:yes', 'Yes, correct');
        $this->assertSame(BookingStatus::AwaitingPayment, $booking->status);
        $this->assertSame('cta', $this->lastSent()['type']);
        $this->assertSame($booking->openPayment()->url(), $this->lastSent()['url']);
        $this->assertGreaterThan($booking->fare_amount, $booking->quote_amount);
        $this->assertTrue($booking->hold_expires_at->eq(now()->addHours(2)));

        $this->pay($booking);

        $booking->refresh();
        $this->assertSame(BookingStatus::Ticketed, $booking->status);
        $this->assertNotEmpty($booking->pnr);
        $this->assertSame($booking->quote_amount, $booking->paid_amount);
        Storage::disk('local')->assertExists($booking->ticket_path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($booking->ticket_path));

        $types = array_column(FakeWhatsApp::$sent, 'type');
        $this->assertContains('document', $types);
        $this->assertSame(['save:yes', 'save:no'], array_keys($this->lastSent()['buttons']));

        $this->tap('save:yes', 'Yes, save it');
        $this->assertNotNull(Passport::query()->first()->consent_at);
    }

    public function test_missing_details_are_asked_for_one_at_a_time(): void
    {
        $booking = $this->say('Hi, I want to go to Dubai');
        $this->assertSame(BookingStatus::CollectingTrip, $booking->status);
        $this->assertStringContainsString('flying from', $this->lastSent()['body']);

        $this->say('From Abuja');
        $this->assertStringContainsString('What date', $this->lastSent()['body']);

        $this->say('18 October');
        $this->assertStringContainsString('How many people', $this->lastSent()['body']);

        $booking = $this->say('2 adults');
        $this->assertSame(BookingStatus::AwaitingPassport, $booking->status);
        $this->assertSame(['ABV', 'DXB', 2], [$booking->origin, $booking->destination, $booking->travellers]);
    }

    public function test_groups_send_one_passport_per_traveller(): void
    {
        $this->say('Abuja to Istanbul 25 October, 2 adults');
        $this->sendPhoto('SAMPLE NAME:KABIR NURA');
        $this->assertStringContainsString('traveller 2 of 2', $this->lastSent()['body']);

        $booking = $this->sendPhoto('SAMPLE NAME:KABIR HALIMA');
        $this->assertSame(BookingStatus::Confirming, $booking->status);
        $this->assertStringContainsString('NURA KABIR', $this->lastSent()['body']);
        $this->assertStringContainsString('HALIMA KABIR', $this->lastSent()['body']);
    }

    public function test_blurry_photos_are_retried_then_handed_to_a_person(): void
    {
        $this->say('Kano to Jeddah 12 October just me');

        $booking = $this->sendPhoto('BLURRY');
        $this->assertSame(BookingStatus::AwaitingPassport, $booking->status);
        $this->assertStringContainsString('passport number', $this->lastSent()['body']);
        $this->assertSame('Blurry photo · asked to resend', $booking->flag);

        $this->sendPhoto('BLURRY');
        $booking = $this->sendPhoto('BLURRY');
        $this->assertTrue($booking->bot_paused);

        // While paused, the bot stays quiet.
        $count = count(FakeWhatsApp::$sent);
        $this->say('hello?');
        $this->assertCount($count, FakeWhatsApp::$sent);
    }

    public function test_client_fixes_a_misread_date_of_birth(): void
    {
        $this->say('Kano to Jeddah 12 October just me');
        $this->sendPhoto();

        $this->tap('confirm:fix', 'Fix something');
        $this->assertSame('list', $this->lastSent()['type']);
        $this->assertArrayHasKey('fix:dob', $this->lastSent()['rows']);

        $this->tap('fix:dob', 'Date of birth');
        $this->assertStringContainsString('day, month, year', $this->lastSent()['body']);

        $this->say('32 13 1988');
        $this->assertStringContainsString("couldn't read", $this->lastSent()['body']);

        $booking = $this->say('17 11 1988');
        $this->assertSame('1988-11-17', Passport::query()->first()->date_of_birth);
        $this->assertStringContainsString('17 Nov 1988', $this->lastSent()['body']);
        $this->assertSame(BookingStatus::Confirming, $booking->status);

        $booking = $this->say('yes');
        $this->assertSame(BookingStatus::AwaitingPayment, $booking->status);
    }

    public function test_expired_hold_is_requoted_when_the_client_returns(): void
    {
        $booking = $this->reachQuote();
        $firstLink = $booking->openPayment();

        $this->travel(3)->hours();
        app(PaymentService::class)->expireHolds();
        $this->assertSame(BookingStatus::Expired, $booking->fresh()->status);
        $this->assertSame('expired', $firstLink->fresh()->status);

        $this->get(route('pay.show', $firstLink->token))->assertOk()->assertSee('no longer held');

        $booking = $this->say('Sorry I didn’t pay yesterday. Is the flight still available?');
        $this->assertSame(BookingStatus::AwaitingPayment, $booking->status);
        $this->assertNotSame($firstLink->id, $booking->openPayment()->id);
        $this->assertStringContainsString('price hold ended', $this->lastSent()['body']);
    }

    public function test_hausa_speakers_get_hausa_replies(): void
    {
        $this->say('Sannu, daga Kano zuwa Jeddah 12 October ni kadai');
        $this->assertStringContainsString('fasfo', $this->lastSent()['body']);

        $this->say('ENGLISH');
        $this->assertStringContainsString('English', $this->lastSent()['body']);
    }

    public function test_help_hands_the_chat_to_a_person(): void
    {
        $this->say('Kano to Jeddah 12 October just me');
        $booking = $this->say('HELP');

        $this->assertTrue($booking->bot_paused);
        $this->assertStringContainsString('team', $this->lastSent()['body']);
    }

    public function test_repeat_client_can_reuse_a_saved_passport(): void
    {
        $booking = $this->reachQuote();
        $this->pay($booking);
        $this->tap('save:yes', 'Yes, save it');

        $this->travel(1)->days();
        $this->say('Kano to Lagos 20 October just me');
        $this->assertSame(['passport:saved', 'passport:new'], array_keys($this->lastSent()['buttons']));

        $booking = $this->tap('passport:saved', 'Use saved passport');
        $this->assertSame(BookingStatus::Confirming, $booking->status);
    }

    public function test_duplicate_webhook_deliveries_are_ignored(): void
    {
        $in = new \App\Services\InboundMessage($this->phone, 'text', 'Kano to Jeddah 12 October just me', waId: 'wamid.same');
        app(\App\Services\Conversation::class)->handle($in);
        $count = count(FakeWhatsApp::$sent);
        app(\App\Services\Conversation::class)->handle($in);

        $this->assertCount($count, FakeWhatsApp::$sent);
    }
}
