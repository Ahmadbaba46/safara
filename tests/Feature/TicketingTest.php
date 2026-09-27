<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Payment;
use App\Models\User;
use App\Services\Settings;

class TicketingTest extends FlowTestCase
{
    public function test_a_small_rise_is_absorbed_and_ticketed(): void
    {
        $booking = $this->reachQuote();
        $this->fareRisesBy($booking, 2000);
        $this->pay($booking);

        $booking->refresh();
        $this->assertSame(BookingStatus::Ticketed, $booking->status);
        $this->assertSame($booking->quote_amount + 2000, $booking->ticketed_fare);
        $this->assertSame(-2000, $booking->margin());
    }

    public function test_a_big_rise_waits_for_review_and_the_client_can_choose_a_refund(): void
    {
        $booking = $this->reachQuote();
        $this->fareRisesBy($booking, 38000);
        $this->pay($booking);

        $booking->refresh();
        $this->assertSame(BookingStatus::FareReview, $booking->status);
        $this->assertStringContainsString('₦38,000', $booking->flag);

        $operator = User::factory()->create();
        $this->actingAs($operator)->get(route('bookings.review', $booking))->assertOk()->assertSee('The fare rose after payment');
        $this->actingAs($operator)->post(route('bookings.decide', $booking), ['choice' => 'choose'])->assertRedirect(route('bookings.show', $booking));

        $booking->refresh();
        $this->assertSame(BookingStatus::AwaitingChoice, $booking->status);
        $this->assertArrayHasKey('fare:refund', $this->lastSent()['buttons']);
        $this->assertStringContainsString('went up by ₦38,000', $this->lastSent()['body']);

        $booking = $this->tap('fare:refund', 'Full refund');
        $this->assertSame(BookingStatus::Refunded, $booking->status);
        $this->assertSame('sent', Payment::query()->where('kind', 'refund')->first()->status);
    }

    public function test_operator_can_ask_for_the_difference(): void
    {
        $booking = $this->reachQuote();
        $this->fareRisesBy($booking, 38000);
        $this->pay($booking);

        $operator = User::factory()->create();
        $this->actingAs($operator)->post(route('bookings.decide', $booking), ['choice' => 'difference']);

        $booking->refresh();
        $this->assertSame(BookingStatus::AwaitingDifference, $booking->status);
        $diff = $booking->openPayment();
        $this->assertSame('difference', $diff->purpose);
        $this->assertSame(38000, $diff->amount);

        $this->pay($booking);
        $this->assertSame(BookingStatus::Ticketed, $booking->fresh()->status);
    }

    public function test_auto_issue_off_holds_the_booking_for_a_person(): void
    {
        app(Settings::class)->set(['auto_issue' => false]);
        $booking = $this->reachQuote();
        $this->pay($booking);

        $booking->refresh();
        $this->assertSame(BookingStatus::Paid, $booking->status);
        $this->assertStringContainsString('ready', $booking->flag);

        $operator = User::factory()->create();
        $offer = $booking->selectedOffer();
        $this->actingAs($operator)->post(route('bookings.issue', $booking), ['offer_id' => $offer->id])->assertRedirect();
        $this->assertSame(BookingStatus::Ticketed, $booking->fresh()->status);
    }

    public function test_no_flights_asks_for_another_date(): void
    {
        \Illuminate\Support\Facades\Cache::put(\App\Integrations\Flights\FakeFlightSearch::EMPTY_KEY, true);
        $this->say('Kano to Jeddah 12 October just me');
        $this->sendPhoto();
        $booking = $this->tap('confirm:yes', 'Yes, correct');

        $this->assertSame(BookingStatus::CollectingTrip, $booking->status);
        $this->assertStringContainsString('another date', $this->lastSent()['body']);

        $booking = $this->say('14 October');
        $this->assertSame(BookingStatus::AwaitingPayment, $booking->status);
        $this->assertSame('2026-10-14', $booking->depart_on->toDateString());
    }
}
