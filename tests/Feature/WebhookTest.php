<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Message;

class WebhookTest extends FlowTestCase
{
    private function payload(array $message): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '1', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'contacts' => [['wa_id' => $this->phone, 'profile' => ['name' => 'Aisha']]],
            'messages' => [$message],
        ]]]]]];
    }

    public function test_meta_verification_handshake(): void
    {
        config(['safara.meta.verify_token' => 'let-me-in']);

        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=let-me-in&hub.challenge=12345')->assertOk()->assertSee('12345');
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345')->assertForbidden();
    }

    public function test_inbound_text_starts_a_booking(): void
    {
        $this->postJson('/webhooks/whatsapp', $this->payload([
            'from' => $this->phone, 'id' => 'wamid.1', 'timestamp' => '1790000000', 'type' => 'text',
            'text' => ['body' => 'Kano to Jeddah 12 October just me'],
        ]))->assertOk();

        $booking = Booking::query()->firstOrFail();
        $this->assertSame(BookingStatus::AwaitingPassport, $booking->status);
        $this->assertSame('Aisha', $booking->client->name);
    }

    public function test_button_replies_are_understood(): void
    {
        $this->say('Kano to Jeddah 12 October just me');
        $this->sendPhoto();

        $this->postJson('/webhooks/whatsapp', $this->payload([
            'from' => $this->phone, 'id' => 'wamid.2', 'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'confirm:yes', 'title' => 'Yes, correct']],
        ]))->assertOk();

        $this->assertSame(BookingStatus::AwaitingPayment, Booking::query()->first()->status);
    }

    public function test_bad_signatures_are_rejected_when_a_secret_is_set(): void
    {
        config(['safara.meta.app_secret' => 'shh']);
        $body = json_encode($this->payload(['from' => $this->phone, 'id' => 'wamid.3', 'type' => 'text', 'text' => ['body' => 'hi']]));

        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=nope'], $body)->assertStatus(401);

        $good = 'sha256='.hash_hmac('sha256', $body, 'shh');
        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $good], $body)->assertOk();
    }

    public function test_delivery_statuses_update_messages(): void
    {
        $this->say('Kano to Jeddah 12 October just me');
        $out = Message::query()->where('direction', 'out')->latest('id')->first();

        $this->postJson('/webhooks/whatsapp', ['entry' => [['changes' => [['value' => ['statuses' => [['id' => $out->wa_id, 'status' => 'read']]]]]]]])->assertOk();

        $this->assertSame('read', $out->fresh()->status);
    }
}
