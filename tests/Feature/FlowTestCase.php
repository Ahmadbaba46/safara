<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Integrations\Flights\FakeFlightSearch;
use App\Integrations\WhatsApp\FakeWhatsApp;
use App\Models\Booking;
use App\Models\Client;
use App\Services\Conversation;
use App\Services\InboundMessage;
use Database\Seeders\TemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Helpers for driving the WhatsApp conversation end to end with the fake drivers. */
abstract class FlowTestCase extends TestCase
{
    use RefreshDatabase;

    protected string $phone = '2348030004417';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-27 10:00:00');
        Storage::fake('local');
        FakeWhatsApp::reset();
        $this->seed(TemplateSeeder::class);
        config([
            'safara.drivers.whatsapp' => 'fake',
            'safara.drivers.passport_reader' => 'fake',
            'safara.drivers.trip_parser' => 'rules',
            'safara.drivers.flights' => 'fake',
            'safara.drivers.payments' => 'fake',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function say(string $text): ?Booking
    {
        return app(Conversation::class)->handle(new InboundMessage($this->phone, 'text', $text, waId: 'wamid.'.Str::random(10), profileName: 'Aisha'));
    }

    protected function tap(string $replyId, string $title = ''): ?Booking
    {
        return app(Conversation::class)->handle(new InboundMessage($this->phone, 'reply', $title, replyId: $replyId, waId: 'wamid.'.Str::random(10)));
    }

    protected function sendPhoto(string $bytes = 'SAMPLE'): ?Booking
    {
        $id = 'media'.Str::random(8);
        Storage::disk('local')->put('simulator/'.$id, $bytes);

        return app(Conversation::class)->handle(new InboundMessage($this->phone, 'image', null, mediaId: $id, mime: 'image/jpeg', waId: 'wamid.'.Str::random(10)));
    }

    /** The last thing the bot sent. */
    protected function lastSent(): array
    {
        return end(FakeWhatsApp::$sent) ?: [];
    }

    protected function booking(): Booking
    {
        return Booking::query()->where('client_id', Client::query()->where('phone', $this->phone)->value('id'))->latest('id')->firstOrFail();
    }

    /** Run the chat up to a sent quote. */
    protected function reachQuote(string $trip = 'Salam. I need a flight Kano to Jeddah, 12 October, just me.'): Booking
    {
        $this->say($trip);
        $this->sendPhoto();
        $this->tap('confirm:yes', 'Yes, correct');

        $booking = $this->booking();
        $this->assertSame(BookingStatus::AwaitingPayment, $booking->status);

        return $booking;
    }

    /** Pay the open link through the test gateway pages. */
    protected function pay(Booking $booking): void
    {
        $payment = $booking->openPayment();
        $this->assertNotNull($payment);

        $this->get(route('pay.show', $payment->token))->assertOk()->assertSee($payment->amountLabel());
        $this->post(route('pay.start', $payment->token), ['method' => 'card'])->assertRedirect(route('pay.fake', ['token' => $payment->token, 'method' => 'card']));
        $this->post(route('pay.fake.complete', $payment->token), ['outcome' => 'success', 'method' => 'card'])->assertRedirect(route('pay.return', $payment->token));
        $this->get(route('pay.return', $payment->token))->assertRedirect(route('pay.done', $payment->token));
    }

    /** Make the fare come back $over naira above the quote after payment. */
    protected function fareRisesBy(Booking $booking, int $over): void
    {
        $booking->refresh();
        FakeFlightSearch::bumpNextSearch((int) ceil(($booking->quote_amount - $booking->fare_amount + $over) / max(1, $booking->travellers)));
    }
}
