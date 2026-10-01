<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Client;
use App\Models\Message;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** The Safara app: the whole booking, in the browser, with no WhatsApp. */
class AppChatTest extends FlowTestCase
{
    /** Start a profile and return the device cookie. */
    private function join(string $name = 'Aisha Bello', string $phone = '0803 000 4417'): string
    {
        $this->defaultCookies = []; // a fresh device
        $res = $this->postJson(route('app.start'), ['name' => $name, 'phone' => $phone, 'language' => 'en'])->assertOk();

        return $res->getCookie('safara_app')->getValue();
    }

    private function as(string $token): static
    {
        return $this->withCredentials()->withCookie('safara_app', $token);
    }

    public function test_the_app_loads_for_everyone_and_the_desk_still_needs_a_login(): void
    {
        $this->get('/')->assertOk()->assertSee('Book your flight in a chat')->assertSee('manifest.webmanifest', false);
        $this->get('/desk')->assertRedirect('/login');
        $this->assertFileExists(public_path('manifest.webmanifest'));
        $this->assertFileExists(public_path('sw.js'));
    }

    public function test_a_device_needs_a_profile_before_it_can_chat(): void
    {
        $this->postJson(route('app.send'), ['kind' => 'text', 'text' => 'hello'])->assertForbidden();
        $this->getJson(route('app.messages'))->assertOk()->assertJson(['messages' => []]);
        $this->postJson(route('app.start'), ['name' => '', 'language' => 'en'])->assertUnprocessable();
        $this->postJson(route('app.start'), ['name' => 'Aisha', 'phone' => '123', 'language' => 'en'])->assertUnprocessable();
    }

    public function test_a_client_books_a_flight_end_to_end_inside_the_app(): void
    {
        $token = $this->join();
        $client = Client::query()->firstOrFail();
        $this->assertSame('app', $client->channel);
        $this->assertSame('2348030004417', $client->contact_phone);
        $this->assertNotSame($token, $client->app_token, 'only a hash of the device token is stored');
        $this->phone = $client->phone;

        // Trip, then passport photo uploaded from the phone.
        $res = $this->as($token)->postJson(route('app.send'), ['kind' => 'text', 'text' => 'Salam. I need a flight Kano to Jeddah, 12 October, just me.', 'after' => 0])->assertOk();
        $this->assertSame('in', $res->json('messages.0.dir'));
        $this->assertSame('out', collect($res->json('messages'))->last()['dir']);

        $res = $this->as($token)->postJson(route('app.send'), ['kind' => 'photo', 'photo' => UploadedFile::fake()->image('passport.jpg')])->assertOk();
        $this->assertSame(BookingStatus::Confirming, $this->booking()->status);

        // The latest question has live buttons, older ones don't.
        $last = collect($res->json('messages'))->last();
        $this->assertTrue($last['live']);
        $this->assertContains('confirm:yes', array_column($last['buttons'], 'id'));

        $res = $this->as($token)->postJson(route('app.send'), ['kind' => 'reply', 'reply_id' => 'confirm:yes', 'text' => 'Yes, correct'])->assertOk();
        $booking = $this->booking();
        $this->assertSame(BookingStatus::AwaitingPayment, $booking->status);
        $cta = collect($res->json('messages'))->firstWhere('type', 'cta');
        $this->assertSame(route('pay.show', $booking->openPayment()->token), $cta['url']);
        $this->assertSame($booking->reference, $res->json('booking.reference'));

        // Nothing went out over WhatsApp.
        $this->assertSame([], \App\Integrations\WhatsApp\FakeWhatsApp::$sent);

        // Pay on the pay page; it brings the client back to the app.
        $this->pay($booking);
        $this->get(route('pay.done', $booking->payments()->first()->token))->assertOk()->assertSee(route('app'), false)->assertDontSee('WhatsApp');

        // The e-ticket lands in the chat and downloads for its owner only.
        $this->assertSame(BookingStatus::Ticketed, $booking->fresh()->status);
        $doc = Message::query()->where('type', 'document')->firstOrFail();
        $poll = $this->as($token)->getJson(route('app.messages', ['after' => 0]))->assertOk();
        $file = collect($poll->json('messages'))->firstWhere('type', 'document');
        $this->assertSame(route('app.document', $doc), $file['file']['url']);

        $this->as($token)->get(route('app.document', $doc))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $other = $this->join('Someone Else', '');
        $this->as($other)->get(route('app.document', $doc))->assertNotFound();
        $this->defaultCookies = [];
        $this->get(route('app.document', $doc))->assertNotFound();
    }

    public function test_polling_only_returns_new_messages(): void
    {
        $token = $this->join();
        $this->as($token)->postJson(route('app.send'), ['kind' => 'text', 'text' => 'hello'])->assertOk();
        $all = $this->as($token)->getJson(route('app.messages'))->json('messages');
        $this->assertNotEmpty($all);
        $this->as($token)->getJson(route('app.messages', ['after' => end($all)['id']]))->assertJson(['messages' => []]);
    }

    public function test_a_client_can_delete_their_data(): void
    {
        $token = $this->join();
        $this->as($token)->postJson(route('app.send'), ['kind' => 'text', 'text' => 'hello'])->assertOk();
        $this->assertSame(1, Client::query()->count());

        $this->as($token)->postJson(route('app.forget'))->assertOk();
        $this->assertSame(0, Client::query()->count());
        $this->assertSame(0, Message::query()->count());
    }

    public function test_the_desk_sees_app_clients_and_can_reply_to_them(): void
    {
        $token = $this->join();
        $this->as($token)->postJson(route('app.send'), ['kind' => 'text', 'text' => 'Kano to Jeddah 12 October just me'])->assertOk();
        $this->phone = Client::query()->firstOrFail()->phone;
        $booking = $this->booking();

        $user = \App\Models\User::factory()->create();
        $this->actingAs($user)->get(route('bookings.chat', $booking))->assertOk()->assertSee('+2348030004417');
        $this->actingAs($user)->post(route('bookings.reply', $booking), ['body' => 'Hi, this is a person.'])->assertRedirect();

        $poll = $this->as($token)->getJson(route('app.messages'))->json('messages');
        $this->assertSame('Hi, this is a person.', collect($poll)->last()['body']);
        $this->assertSame([], \App\Integrations\WhatsApp\FakeWhatsApp::$sent);
    }

    public function test_the_app_can_be_switched_off(): void
    {
        config(['safara.app.enabled' => false]);
        $this->get('/')->assertNotFound();
    }
}
