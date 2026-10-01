<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Operator ticketing: no flight API. A person quotes and books, the bot does the rest. */
class ManualTicketingTest extends FlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['safara.drivers.flights' => 'manual']);
    }

    private function toAwaitingQuote()
    {
        $this->say('Salam. I need a flight Kano to Jeddah, 12 October, just me.');
        $this->sendPhoto();
        $this->tap('confirm:yes', 'Yes, correct');

        return $this->booking();
    }

    public function test_after_confirming_the_booking_waits_for_a_person_to_quote(): void
    {
        $booking = $this->toAwaitingQuote();

        $this->assertSame(BookingStatus::AwaitingQuote, $booking->status);
        $this->assertSame('Needs your quote', $booking->flag);
        $this->assertTrue(Message::query()->where('body', 'like', '%preparing your best price%')->exists());
        $this->assertSame(0, $booking->payments()->count());

        // Client chatting meanwhile gets the same calm answer, not "booking your seat".
        $this->say('hello?');
        $this->assertStringContainsString('preparing your best price', $this->lastSentBody());
    }

    public function test_the_whole_flow_with_an_operator_quote_and_a_recorded_ticket(): void
    {
        $booking = $this->toAwaitingQuote();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('bookings.show', $booking))->assertOk()->assertSee('Send a quote')->assertDontSee('Record the ticket</h2>', false);

        $this->actingAs($user)->post(route('bookings.manualQuote', $booking), [
            'airline' => 'Air Peace', 'price' => 685000, 'cost' => 640000, 'baggage' => '1 x 23kg',
            'out_flight' => 'p4 7120', 'out_departs' => '2026-10-12T08:30', 'out_arrives' => '2026-10-12T13:10',
        ])->assertRedirect();

        $booking->refresh();
        $this->assertSame(BookingStatus::AwaitingPayment, $booking->status);
        $this->assertSame(685000, (int) $booking->quote_amount);
        $this->assertSame(640000, (int) $booking->fare_amount);
        $this->assertNotNull($booking->openPayment());
        $this->assertSame('cta', Message::query()->latest('id')->value('type'));

        $this->pay($booking);
        $booking->refresh();
        $this->assertSame(BookingStatus::Paid, $booking->status);
        $this->assertSame('Paid · book the ticket', $booking->flag);

        $this->actingAs($user)->get(route('bookings.show', $booking))->assertOk()->assertSee('Record the ticket');

        // The operator books elsewhere and records the PNR; the airline's PDF goes to the client.
        Storage::fake('local');
        $this->actingAs($user)->post(route('bookings.manualIssue', $booking), [
            'pnr' => 'abc123', 'ticket_numbers' => '0712345678901, 0712345678902',
            'ticket_pdf' => UploadedFile::fake()->create('eticket.pdf', 50, 'application/pdf'),
        ])->assertRedirect();

        $booking->refresh();
        $this->assertSame(BookingStatus::Ticketed, $booking->status);
        $this->assertSame('ABC123', $booking->pnr);
        $this->assertSame(['0712345678901', '0712345678902'], $booking->stateGet('ticket_numbers'));
        $this->assertSame('tickets/'.$booking->reference.'-ABC123-airline.pdf', $booking->ticket_path);
        $this->assertSame('document', Message::query()->where('type', 'document')->value('type'));
        $this->assertSame(45000, $booking->margin());
    }

    public function test_without_an_uploaded_pdf_safara_sends_its_own_receipt(): void
    {
        $booking = $this->toAwaitingQuote();
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('bookings.manualQuote', $booking), ['airline' => 'Ethiopian', 'price' => 900000])->assertRedirect();
        $this->pay($booking->refresh());

        $this->actingAs($user)->post(route('bookings.manualIssue', $booking), ['pnr' => 'XYZ789'])->assertRedirect();

        $booking->refresh();
        $this->assertSame(BookingStatus::Ticketed, $booking->status);
        $this->assertSame('tickets/'.$booking->reference.'-XYZ789.pdf', $booking->ticket_path);
        $this->assertTrue(Storage::disk('local')->exists($booking->ticket_path));
    }

    public function test_cannot_record_a_ticket_before_payment_and_forms_need_valid_input(): void
    {
        $booking = $this->toAwaitingQuote();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('bookings.manualIssue', $booking), ['pnr' => 'ABC123'])->assertStatus(409);
        $this->actingAs($user)->post(route('bookings.manualQuote', $booking), ['airline' => '', 'price' => 5])->assertSessionHasErrors(['airline', 'price']);
    }

    public function test_the_manual_screens_are_off_when_a_flight_api_is_used(): void
    {
        config(['safara.drivers.flights' => 'fake']);
        $booking = $this->reachQuote();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('bookings.manualQuote', $booking), ['airline' => 'X', 'price' => 50000])->assertNotFound();
    }

    private function lastSentBody(): string
    {
        return (string) Message::query()->where('direction', 'out')->latest('id')->value('body');
    }
}
