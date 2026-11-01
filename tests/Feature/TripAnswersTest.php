<?php

namespace Tests\Feature;

use App\Contracts\TripParser;
use App\Enums\BookingStatus;
use App\Models\Message;
use App\Support\TripRequest;
use App\Support\TripRules;
use PHPUnit\Framework\Attributes\DataProvider;

/** Short replies to "how many people?" and "where from?" must work the first time. */
class TripAnswersTest extends FlowTestCase
{
    public static function headcounts(): array
    {
        return [
            'digit' => ['5', 5],
            'word' => ['five', 5],
            'word and people' => ['five people', 5],
            'with please' => ['5 please', 5],
            'me and others' => ['me and 4 others', 5],
            'family' => ['family of 5', 5],
            'two' => ['2', 2],
            'hausa' => ['biyar', 5],
            'just me' => ['just me', 1],
        ];
    }

    #[DataProvider('headcounts')]
    public function test_a_short_answer_to_how_many_people_is_accepted_first_time(string $reply, int $expected): void
    {
        $this->say('Kano to Jeddah, 12 October');
        $this->assertSame(BookingStatus::CollectingTrip, $this->booking()->status);
        $this->assertStringContainsString('How many people', $this->lastBody());

        $this->say($reply);

        $booking = $this->booking();
        $this->assertSame($expected, (int) $booking->travellers, "“{$reply}” should mean {$expected}");
        $this->assertSame(BookingStatus::AwaitingPassport, $booking->status, 'moves on to the passport, no second ask');
        $this->assertSame('2026-10-12', $booking->depart_on->toDateString(), 'a bare number must not change the date');
        $this->assertStringNotContainsString('How many people', $this->lastBody());
    }

    public function test_an_answer_that_is_not_a_number_asks_again_politely(): void
    {
        $this->say('Kano to Jeddah, 12 October');
        $this->say('not sure yet');

        $this->assertSame(BookingStatus::CollectingTrip, $this->booking()->status);
        $this->assertStringContainsString('How many people', $this->lastBody());
    }

    public function test_a_short_answer_is_not_sent_to_the_ai_parser(): void
    {
        $spy = new class implements TripParser
        {
            public static int $calls = 0;

            public function parse(string $text, \DateTimeImmutable $today): TripRequest
            {
                self::$calls++;

                return (new TripRules($today))->parse($text);
            }
        };
        $this->app->instance(TripParser::class, $spy);

        $this->say('Kano to Jeddah, 12 October');
        $calls = $spy::$calls;
        $this->say('5');

        $this->assertSame($calls, $spy::$calls, 'a bare answer needs no AI call (and cannot be misread as a date)');
        $this->assertSame(5, (int) $this->booking()->travellers);
    }

    public function test_where_from_answered_with_one_city_keeps_the_destination(): void
    {
        $this->say('Jeddah, 12 October, just me');
        $booking = $this->booking();
        $this->assertSame('JED', $booking->destination);
        $this->assertNull($booking->origin);
        $this->assertStringContainsString('flying from', $this->lastBody());

        $this->say('Kano');

        $booking = $this->booking();
        $this->assertSame(['KAN', 'JED'], [$booking->origin, $booking->destination], 'a lone city answers "where from", it does not replace the destination');
        $this->assertSame(BookingStatus::AwaitingPassport, $booking->status);
    }

    public function test_a_longer_reply_that_changes_the_trip_is_still_parsed_as_a_trip(): void
    {
        $this->say('Kano to Jeddah, 12 October');
        $this->say('Actually Lagos to Abuja tomorrow, 2 people');

        $booking = $this->booking();
        $this->assertSame(['LOS', 'ABV', 2], [$booking->origin, $booking->destination, (int) $booking->travellers]);
    }

    private function lastBody(): string
    {
        return (string) Message::query()->where('direction', 'out')->latest('id')->value('body');
    }
}
