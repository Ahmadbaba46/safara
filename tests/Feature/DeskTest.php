<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Settings;
use Database\Seeders\DemoSeeder;

class DeskTest extends FlowTestCase
{
    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/desk')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Log in');
    }

    public function test_operator_can_log_in(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass']);
        $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass'])->assertRedirect(route('desk'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_every_desk_screen_renders_with_demo_data(): void
    {
        $this->seed(DemoSeeder::class);
        $this->actingAs(User::factory()->create());

        $booking = Booking::query()->where('status', 'fare_review')->firstOrFail();
        $client = Client::query()->firstOrFail();

        $this->get(route('desk'))->assertOk()->assertSee('Today’s desk')->assertSee('Nura Kabir');
        $this->get(route('desk', ['route' => 'hajj']))->assertOk();
        $this->get(route('bookings.index'))->assertOk()->assertSee($booking->reference);
        $this->get(route('bookings.index', ['tab' => 'closed']))->assertOk()->assertSee('Kabiru Ahmed');
        $this->get(route('bookings.index', ['q' => 'Aisha']))->assertOk()->assertSee('Aisha Bello');
        $this->get(route('bookings.export'))->assertOk();
        $this->get(route('bookings.show', $booking))->assertOk()->assertSee('Fare check');
        $this->get(route('bookings.review', $booking))->assertOk();
        $this->get(route('bookings.chat', $booking))->assertOk();
        $this->get(route('bookings.create'))->assertOk();
        $this->get(route('clients.index'))->assertOk();
        $this->get(route('clients.index', ['c' => $client->id]))->assertOk()->assertSee($client->name);
        $this->get(route('payments.index'))->assertOk()->assertSee('Collected today');
        $this->get(route('payments.index', ['tab' => 'refunds']))->assertOk()->assertSee('Refund due');
        $this->get(route('templates.index'))->assertOk()->assertSee('Welcome &amp; trip question', false);
        $this->get(route('templates.index', ['t' => 'fare_changed', 'lang' => 'ha']))->assertOk();
        $this->get(route('settings'))->assertOk()->assertSee('Ticketing rules');
        $this->get(route('simulator'))->assertOk();
    }

    public function test_settings_are_saved(): void
    {
        $this->actingAs(User::factory()->create());
        $this->put(route('settings.update'), [
            'markup_percent' => 8, 'min_margin' => 12000, 'round_to' => 5000, 'hold_minutes' => 60,
            'payment_methods' => ['card', 'transfer'], 'absorb_limit' => 3000, 'night_start' => '23:00', 'night_end' => '05:00',
            'retention' => 'after_travel', 'refund_time' => '3 working days', 'fx_usd' => 1600, 'fx_gbp' => 2000, 'fx_eur' => 1700,
            'auto_issue' => '1',
        ])->assertRedirect();

        $s = app(Settings::class);
        $s->forget();
        $this->assertSame(8.0, (float) $s->get('markup_percent'));
        $this->assertFalse($s->get('absorb'));
        $this->assertTrue($s->get('auto_issue'));
        $this->assertSame(['card', 'transfer'], $s->get('payment_methods'));
        $this->assertSame(60, $s->holdMinutes());
    }

    public function test_templates_are_edited(): void
    {
        $this->actingAs(User::factory()->create());
        $t = MessageTemplate::query()->where('key', 'quote')->firstOrFail();

        $this->put(route('templates.update', $t), [
            'body_en' => 'Your price: {amount}.', 'body_ha' => 'Farashinku: {amount}.',
            'buttons_en' => ['Pay {amount}'], 'buttons_ha' => ['Biya {amount}'],
        ])->assertRedirect();

        $this->assertSame('Your price: {amount}.', $t->fresh()->body_en);
    }

    public function test_manual_quote_messages_the_client(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post(route('bookings.store'), [
            'phone' => '0803 555 1234', 'name' => 'Walk In', 'origin' => 'KAN', 'destination' => 'JED',
            'depart_on' => '2026-10-20', 'travellers' => 1, 'language' => 'en',
        ])->assertRedirect();

        $booking = Booking::query()->latest('id')->first();
        $this->assertSame('2348035551234', $booking->client->phone);
        $this->assertSame('manual', $booking->source);
        $this->assertStringContainsString('passport', $this->lastSent()['body']);
    }

    public function test_operator_can_take_over_and_reply(): void
    {
        $booking = $this->say('Kano to Jeddah 12 October just me');
        $this->actingAs(User::factory()->create(['name' => 'Ahmad']));

        $this->post(route('bookings.bot', $booking))->assertRedirect();
        $this->assertTrue($booking->fresh()->bot_paused);

        $this->post(route('bookings.reply', $booking), ['body' => 'Hi, this is Ahmad. I can help.'])->assertRedirect();
        $this->assertSame('Hi, this is Ahmad. I can help.', $this->lastSent()['body']);
    }
}
