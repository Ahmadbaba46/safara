<?php

namespace Database\Seeders;

use App\Enums\BookingStatus as S;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Offer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Sample bookings so the desk isn't empty while you explore:
 *   php artisan db:seed --class=DemoSeeder
 * Everything it creates uses phone numbers starting 2340000.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['Musa Garba', 'ABV', 'LOS', 6, 1, S::AwaitingPassport, null, 'Reading passport…', 'info', 2],
            ['Hauwa Sani', 'KAN', 'DXB', 21, 2, S::AwaitingPassport, null, 'Blurry photo · asked to resend', 'warn', 9],
            ['Ibrahim Yusuf', 'KAN', 'MED', 24, 1, S::Confirming, null, 'Client corrected given name', 'mute', 14],
            ['Zainab Ali', 'LOS', 'LHR', 39, 1, S::Confirming, null, null, null, 22],
            ['Aminu Danladi', 'ABV', 'JED', 12, 3, S::AwaitingPayment, [1930000, 2046000], null, null, 72],
            ['Fatima Umar', 'KAN', 'CAI', 17, 1, S::AwaitingPayment, [470000, 498500], null, null, 36],
            ['Sadiq Bala', 'PHC', 'ABV', 3, 1, S::AwaitingPayment, [131000, 142000], null, null, 51],
            ['Aisha Bello', 'KAN', 'JED', 15, 1, S::Paid, [646200, 685000], 'Fare within quote · ready', 'ok', 11],
            ['Nura Kabir', 'ABV', 'IST', 28, 2, S::FareReview, [1236000, 1310000], 'Fare up ₦38,000 · review', 'warn', 4],
            ['Maryam Idris', 'KAN', 'LOS', 1, 1, S::Ticketed, [107000, 118000], null, null, 180],
            ['Usman Tijani', 'ABV', 'DXB', 5, 2, S::Ticketed, [1022000, 1084000], null, null, 260],
            ['Kabiru Ahmed', 'ABV', 'KAN', -1, 1, S::RefundDue, [86000, 96000], 'Refund due · finish it on Payments', 'warn', 1400],
        ];

        foreach ($rows as $i => [$name, $from, $to, $days, $pax, $status, $money, $flag, $tone, $minsAgo]) {
            $client = Client::query()->firstOrCreate(
                ['phone' => '2340000'.str_pad((string) (1000 + $i), 6, '0', STR_PAD_LEFT)],
                ['name' => $name, 'language' => $i % 4 === 1 ? 'ha' : 'en', 'last_inbound_at' => now()->subMinutes($minsAgo)],
            );
            if ($client->bookings()->exists()) {
                continue;
            }

            [$fare, $quote] = $money ?? [null, null];
            $at = now()->subMinutes($minsAgo);
            $booking = $client->bookings()->create([
                'status' => $status,
                'origin' => $from,
                'destination' => $to,
                'depart_on' => today()->addDays($days),
                'travellers' => $pax,
                'fare_amount' => $fare,
                'quote_amount' => $quote,
                'quoted_at' => $quote ? $at : null,
                'hold_expires_at' => $status === S::AwaitingPayment ? now()->addMinutes(120 - $minsAgo) : null,
                'paid_amount' => in_array($status, [S::Paid, S::FareReview, S::Ticketed, S::RefundDue], true) ? $quote : 0,
                'ticketed_fare' => $status === S::Ticketed ? $fare : null,
                'ticketed_at' => $status === S::Ticketed ? $at : null,
                'pnr' => $status === S::Ticketed ? strtoupper(Str::random(6)) : null,
                'flag' => $flag,
                'flag_tone' => $tone,
            ]);
            $booking->forceFill(['created_at' => $at->copy()->subMinutes(20), 'updated_at' => $at])->saveQuietly();

            $booking->event('chat', 'Client started chat', '“'.\App\Support\Iata::city($from).' to '.\App\Support\Iata::city($to).'”');
            if ($quote) {
                $booking->payments()->create([
                    'kind' => 'charge', 'purpose' => 'fare', 'provider' => 'test', 'amount' => $quote,
                    'status' => $booking->paid_amount ? 'confirmed' : 'open',
                    'method' => $booking->paid_amount ? ['card', 'transfer', 'ussd'][$i % 3] : null,
                    'paid_at' => $booking->paid_amount ? $at : null,
                    'expires_at' => $booking->hold_expires_at,
                ]);
                $booking->event('quote', 'Quote sent · '.\App\Support\Money::format($quote), 'Pay link valid for 2 hours');
                Offer::query()->create([
                    'booking_id' => $booking->id, 'provider_offer_id' => 'off_fake_demo'.$i, 'batch' => 'demo-'.$i,
                    'airline' => in_array($to, ['LOS', 'ABV', 'KAN'], true) ? 'Air Peace' : 'EgyptAir',
                    'depart_on' => $booking->depart_on, 'summary' => in_array($to, ['LOS', 'ABV', 'KAN'], true) ? 'direct · 1h 10m' : 'via Cairo · 11h 40m',
                    'stops' => in_array($to, ['LOS', 'ABV', 'KAN'], true) ? 0 : 1, 'baggage' => '1 × checked bag',
                    'amount' => $status === S::FareReview ? $quote + 38000 : $fare, 'original_amount' => $status === S::FareReview ? $quote + 38000 : $fare,
                    'original_currency' => 'NGN', 'passenger_ids' => array_map(fn ($n) => 'pas_fake_'.$n, range(1, $pax)), 'selected' => true,
                    'segments' => [],
                ]);
            }
            if ($booking->paid_amount) {
                $booking->event('payment', 'Payment confirmed · '.\App\Support\Money::format($quote));
            }
            if ($status === S::Ticketed) {
                $booking->event('ticketed', 'Ticket issued · '.$booking->pnr);
            }
            if ($status === S::FareReview) {
                $booking->event('review', 'Needs a fare review', 'Fare up ₦38,000');
            }
            if ($status === S::RefundDue) {
                $booking->payments()->create(['kind' => 'refund', 'purpose' => 'refund', 'provider' => 'test', 'amount' => $quote, 'status' => 'due', 'meta' => ['reason' => 'Airline cancelled the flight']]);
                $booking->event('refund', 'Refund due · '.\App\Support\Money::format($quote), 'Airline cancelled the flight');
            }
        }

        $this->command?->info('Demo bookings added.');
    }
}
