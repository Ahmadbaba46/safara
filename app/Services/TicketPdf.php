<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Offer;
use App\Support\Iata;
use App\Support\PdfWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/** The e-ticket receipt sent on WhatsApp after ticketing. */
class TicketPdf
{
    private const INK = '#17191C';
    private const MUTED = '#5B5F66';
    private const DARK = '#13241F';
    private const GREEN = '#0E5A47';
    private const LINE = '#E3DED2';

    public function generate(Booking $booking, Offer $offer): string
    {
        $pdf = new PdfWriter('E-ticket '.$booking->pnr);
        $brand = config('safara.brand');

        // Header band
        $pdf->rect(0, 0, 595.28, 118, self::DARK)
            ->text(40, 48, $brand, 22, 'bold', '#FFFFFF')
            ->text(40, 74, 'E-ticket receipt', 12, 'regular', '#B9CBC3')
            ->text(40, 96, 'Booking '.$booking->reference, 10, 'regular', '#B9CBC3')
            ->text(555, 52, 'BOOKING REF', 9, 'bold', '#9FDCC3', 'right')
            ->text(555, 84, (string) $booking->pnr, 26, 'monobold', '#FFFFFF', 'right');

        $y = 160;
        $pdf->text(40, $y, Iata::city($booking->origin).' to '.Iata::city($booking->destination), 20, 'bold', self::INK);
        $y += 22;
        $pdf->text(40, $y, $offer->airline.' · '.($booking->return_on ? 'Return' : 'One way').' · '.ucfirst($booking->cabin_class).' · '.($offer->baggage ?? ''), 11, 'regular', self::MUTED);

        // Passengers
        $y += 38;
        $pdf->text(40, $y, 'PASSENGERS', 9, 'bold', self::MUTED);
        $tickets = $booking->stateGet('ticket_numbers', []);
        $y += 8;
        foreach ($booking->passports()->get() as $i => $p) {
            $y += 22;
            $pdf->line(40, $y - 15, 555, $y - 15, self::LINE);
            $pdf->text(40, $y, strtoupper($p->surname.' / '.$p->given_names), 12, 'bold', self::INK)
                ->text(330, $y, 'Passport '.$p->maskedNumber(), 10, 'mono', self::MUTED)
                ->text(555, $y, isset($tickets[$i]) ? 'Ticket '.$tickets[$i] : '', 10, 'mono', self::MUTED, 'right');
        }

        // Flights
        $y += 42;
        $pdf->text(40, $y, 'FLIGHTS', 9, 'bold', self::MUTED);
        $y += 8;
        $segments = $booking->stateGet('segments', $offer->segments ?? []);
        foreach ($segments as $s) {
            $dep = isset($s['departing_at']) ? Carbon::parse($s['departing_at']) : null;
            $arr = isset($s['arriving_at']) ? Carbon::parse($s['arriving_at']) : null;
            $y += 30;
            $pdf->line(40, $y - 20, 555, $y - 20, self::LINE);
            $pdf->text(40, $y, (string) ($s['flight_number'] ?? ''), 12, 'monobold', self::GREEN)
                ->text(120, $y, ($s['origin'] ?? '').'  '.($dep?->format('H:i') ?? ''), 13, 'monobold', self::INK)
                ->text(250, $y, '>', 13, 'mono', self::MUTED)
                ->text(275, $y, ($s['destination'] ?? '').'  '.($arr?->format('H:i') ?? ''), 13, 'monobold', self::INK)
                ->text(555, $y, $dep?->format('D j M Y') ?? '', 11, 'regular', self::INK, 'right');
            $y += 14;
            $pdf->text(120, $y, ($s['origin_city'] ?? '').' to '.($s['destination_city'] ?? ''), 9, 'regular', self::MUTED);
            if ($arr && $dep && $arr->toDateString() !== $dep->toDateString()) {
                $pdf->text(555, $y, 'Arrives '.$arr->format('D j M'), 9, 'regular', self::MUTED, 'right');
            }
        }

        // Payment
        $y += 44;
        $pdf->rect(40, $y - 18, 515, 56, '#F5F2EA');
        $pdf->text(56, $y + 2, 'Total paid', 11, 'regular', self::MUTED)
            ->text(539, $y + 6, 'NGN '.number_format((int) $booking->paid_amount), 18, 'bold', self::INK, 'right')
            ->text(56, $y + 22, 'Issued '.now(config('safara.timezone'))->format('j M Y, H:i'), 9, 'regular', self::MUTED);

        // Notes
        $y += 80;
        foreach ([
            'Check in with this booking reference and the passport shown above.',
            'Arrive at the airport at least 3 hours before international flights, 90 minutes for domestic.',
            'Need help or a change? Reply HELP or CHANGE in your chat with '.$brand.'.',
        ] as $note) {
            $pdf->text(40, $y, '·  '.$note, 10, 'regular', self::INK);
            $y += 18;
        }

        $pdf->line(40, 790, 555, 790, self::LINE)
            ->text(40, 808, $brand.' · '.$booking->reference.' · '.$booking->pnr, 8, 'regular', self::MUTED)
            ->text(555, 808, 'This receipt is not a boarding pass.', 8, 'regular', self::MUTED, 'right');

        $path = 'tickets/'.$booking->reference.'-'.$booking->pnr.'.pdf';
        Storage::disk('local')->put($path, $pdf->output());

        return $path;
    }
}
