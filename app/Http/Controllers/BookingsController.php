<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Passport;
use App\Services\Messenger;
use App\Services\OfferService;
use App\Services\PassportService;
use App\Services\QuoteService;
use App\Services\TicketingService;
use App\Support\Iata;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookingsController extends Controller
{
    private const TABS = ['all' => 'All', 'open' => 'In progress', 'ticketed' => 'Ticketed', 'closed' => 'Refunds & cancelled'];

    private function query(Request $request, string $tab): Builder
    {
        $q = Booking::query()->with('client');
        match ($tab) {
            'open' => $q->whereIn('status', BookingStatus::open()),
            'ticketed' => $q->where('status', BookingStatus::Ticketed),
            'closed' => $q->whereIn('status', BookingStatus::closed()),
            default => null,
        };

        if ($term = trim((string) $request->query('q'))) {
            $digits = preg_replace('/\D/', '', $term);
            $q->where(function (Builder $w) use ($term, $digits) {
                $w->where('reference', 'like', "%$term%")
                    ->orWhere('pnr', 'like', "%$term%")
                    ->orWhereHas('client', function (Builder $c) use ($term, $digits) {
                        $c->where('name', 'like', "%$term%");
                        if (strlen($digits) >= 4) {
                            $c->orWhere('phone', 'like', "%$digits%");
                        }
                    });
            });
        }
        if ($route = $request->query('route')) {
            DeskController::routeFilter($q, $route);
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($source = $request->query('source')) {
            $q->where('source', $source);
        }

        return $q->latest('updated_at');
    }

    public function index(Request $request)
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'all';
        $counts = [
            'all' => Booking::query()->count(),
            'open' => Booking::query()->whereIn('status', BookingStatus::open())->count(),
            'ticketed' => Booking::query()->where('status', BookingStatus::Ticketed)->count(),
            'closed' => Booking::query()->whereIn('status', BookingStatus::closed())->count(),
        ];

        return view('bookings.index', [
            'bookings' => $this->query($request, $tab)->paginate(12)->withQueryString(),
            'tab' => $tab,
            'tabs' => self::TABS,
            'counts' => $counts,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->query($request, $request->query('tab', 'all'))->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Booking', 'Client', 'Phone', 'Route', 'Travel date', 'Return', 'Travellers', 'Quote (NGN)', 'Paid (NGN)', 'Fare (NGN)', 'Status', 'PNR', 'Updated']);
            foreach ($rows as $b) {
                fputcsv($out, [$b->reference, $b->client->displayName(), '+'.$b->client->phone, $b->routeCodes(), $b->depart_on?->toDateString(), $b->return_on?->toDateString(),
                    $b->travellers, $b->quote_amount, $b->paid_amount, $b->ticketed_fare ?? $b->fare_amount, $b->status->label(), $b->pnr, $b->updated_at->toDateTimeString()]);
            }
            fclose($out);
        }, 'safara-bookings-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function show(Booking $booking)
    {
        $booking->load(['client', 'passports', 'events', 'payments']);

        return view('bookings.show', [
            'booking' => $booking,
            'offers' => $booking->latestOffers(),
            'selected' => $booking->selectedOffer(),
            'confirmedPayment' => $booking->payments->where('kind', 'charge')->where('status', 'confirmed')->first(),
        ]);
    }

    public function search(Booking $booking, OfferService $offers)
    {
        abort_unless($booking->origin && $booking->destination && $booking->depart_on, 422, 'The trip is not complete yet.');
        try {
            $found = $offers->search($booking, null, 'desk');
        } catch (\Throwable $e) {
            return back()->with('error', 'Search failed: '.$e->getMessage());
        }
        if ($found->isNotEmpty() && ! $booking->ticketed_at) {
            $booking->offers()->update(['selected' => false]);
            $found->first()->update(['selected' => true]);
        }
        $booking->event('search', 'Operator searched again', $found->count().' offers');

        return back()->with('status', $found->count().' offers found.');
    }

    public function issue(Request $request, Booking $booking, TicketingService $ticketing)
    {
        abort_if($booking->status === BookingStatus::Ticketed, 409, 'Already ticketed.');
        abort_if((int) $booking->paid_amount <= 0, 409, 'The client has not paid yet.');
        $offer = $booking->offers()->findOrFail($request->validate(['offer_id' => 'required|integer'])['offer_id']);

        $booking->event('issue', 'Operator issued the ticket', $offer->airline);
        $ok = $ticketing->issue($booking, $offer, force: $request->boolean('force'));

        return back()->with($ok ? 'status' : 'error', $ok ? 'Ticket issued and sent to the client.' : 'Could not issue — see the flag on the booking.');
    }

    public function review(Booking $booking, Messenger $messenger)
    {
        $booking->load(['client', 'passports']);
        $selected = $booking->selectedOffer();
        $difference = max(0, ($selected?->amount ?? 0) - (int) $booking->paid_amount);
        $alternatives = $booking->offers()->where('batch', 'like', 'alt-%')->where('amount', '<=', $booking->paid_amount)
            ->orderBy('amount')->get()->unique(fn ($o) => $o->depart_on->toDateString())->take(3)->values();
        $alt = $alternatives->first();

        $lang = $booking->client->language ?: 'en';
        $vars = [
            'name' => $booking->passports->first()?->firstName() ?: '',
            'diff' => \App\Support\Money::format($difference),
            'date' => $booking->depart_on?->format('D j M') ?? '',
            'alt_date' => $alt?->depart_on->format('D j M') ?? '',
            'amount' => \App\Support\Money::format($booking->paid_amount),
            'pnr' => 'ABC123',
            'refund_time' => app(\App\Services\Settings::class)->get('refund_time', 'a few working days'),
        ];
        $choose = $messenger->buttonTitles('fare_changed', $vars, $lang);
        if (! $alt) {
            unset($choose[1]);
        }
        $previews = [
            'choose' => ['body' => $messenger->render('fare_changed', $vars, $lang), 'buttons' => array_values($choose), 'cta' => 'Send options to '.($vars['name'] ?: 'the client')],
            'absorb' => ['body' => $messenger->render('ticket_issued', $vars, $lang), 'buttons' => [], 'cta' => 'Ticket now at '.\App\Support\Money::format($selected?->amount)],
            'difference' => ['body' => $messenger->render('fare_pay_diff', $vars, $lang), 'buttons' => $messenger->buttonTitles('fare_pay_diff', $vars, $lang), 'cta' => 'Send pay link'],
            'refund' => ['body' => $messenger->render('refund_sent', $vars, $lang), 'buttons' => [], 'cta' => 'Refund '.\App\Support\Money::format($booking->paid_amount)],
        ];

        return view('bookings.review', [
            'booking' => $booking,
            'selected' => $selected,
            'difference' => $difference,
            'margin' => (int) $booking->quote_amount - (int) ($selected?->amount ?? 0),
            'alternatives' => $alternatives,
            'previews' => $previews,
        ]);
    }

    public function decide(Request $request, Booking $booking, TicketingService $ticketing, OfferService $offers)
    {
        abort_unless(in_array($booking->status, [BookingStatus::FareReview, BookingStatus::Paid, BookingStatus::AwaitingChoice], true), 409, 'This booking is not waiting for a decision.');
        $choice = $request->validate(['choice' => 'required|in:choose,absorb,difference,refund,alternatives'])['choice'];

        if ($choice === 'alternatives') {
            $offers->alternativeWithin($booking, (int) $booking->paid_amount);

            return back()->with('status', 'Checked nearby dates.');
        }

        match ($choice) {
            'choose' => $ticketing->letClientChoose($booking),
            'absorb' => $ticketing->absorb($booking),
            'difference' => $ticketing->askDifference($booking),
            'refund' => $ticketing->refundFull($booking),
        };

        return redirect()->route('bookings.show', $booking)->with('status', match ($choice) {
            'choose' => 'Options sent to the client on WhatsApp.',
            'absorb' => 'Issuing at today’s fare.',
            'difference' => 'Pay link for the difference sent.',
            'refund' => 'Refund started.',
        });
    }

    public function chat(Booking $booking)
    {
        $booking->load('client');
        $messages = $booking->client->messages()->oldest('id')->get()->filter(fn ($m) => $m->booking_id === $booking->id || $m->booking_id === null || $m->created_at->gte($booking->created_at))->values();

        return view('bookings.chat', ['booking' => $booking, 'messages' => $messages]);
    }

    public function reply(Request $request, Booking $booking, Messenger $messenger)
    {
        $body = $request->validate(['body' => 'required|string|max:4000'])['body'];
        $messenger->text($booking->client, $booking, $body, $request->user()->name);
        $booking->setFlag($booking->bot_paused ? 'Replied by hand' : $booking->flag, $booking->bot_paused ? 'mute' : $booking->flag_tone)->save();

        return back();
    }

    public function toggleBot(Booking $booking)
    {
        $booking->update(['bot_paused' => ! $booking->bot_paused]);
        if (! $booking->bot_paused) {
            $booking->setFlag(null)->save();
        }
        $booking->event('handover', $booking->bot_paused ? 'Operator took over the chat' : 'Bot resumed');

        return back()->with('status', $booking->bot_paused ? 'The bot is paused for this chat.' : 'The bot is answering again.');
    }

    public function updatePassport(Request $request, Booking $booking, Passport $passport)
    {
        abort_unless($booking->passports()->whereKey($passport->id)->exists(), 404);
        $data = $request->validate([
            'surname' => 'required|string|max:60',
            'given_names' => 'required|string|max:80',
            'number' => 'required|alpha_num|min:6|max:12',
            'nationality' => 'required|alpha|size:3',
            'date_of_birth' => 'required|date|before:today',
            'sex' => 'required|in:M,F',
            'expiry' => 'required|date|after:today',
        ]);
        $data = array_map(fn ($v) => is_string($v) ? strtoupper(trim($v)) : $v, $data);
        $passport->update($data + ['confidence' => array_fill_keys(array_keys($data), 100)]);
        $booking->event('fix', 'Operator corrected passport details', $passport->maskedNumber());

        return back()->with('status', 'Passport details saved.');
    }

    public function create()
    {
        return view('bookings.create', ['cities' => Iata::CITIES]);
    }

    /** "Manual quote": start a booking for someone who called or walked in. */
    public function store(Request $request, Messenger $messenger)
    {
        $data = $request->validate([
            'phone' => 'required|string|min:10|max:20',
            'name' => 'nullable|string|max:80',
            'origin' => 'required|alpha|size:3',
            'destination' => 'required|alpha|size:3|different:origin',
            'depart_on' => 'required|date|after_or_equal:today',
            'return_on' => 'nullable|date|after_or_equal:depart_on',
            'travellers' => 'required|integer|min:1|max:9',
            'language' => 'required|in:en,ha',
        ]);
        $phone = preg_replace('/\D/', '', $data['phone']);
        if (str_starts_with($phone, '0')) {
            $phone = '234'.substr($phone, 1);
        }

        $client = Client::query()->firstOrCreate(['phone' => $phone], ['name' => $data['name'] ?: null, 'language' => $data['language']]);
        if (! $client->bookings()->whereIn('status', BookingStatus::open())->exists()) {
            $booking = $client->bookings()->create([
                'status' => BookingStatus::AwaitingPassport,
                'source' => 'manual',
                'origin' => strtoupper($data['origin']),
                'destination' => strtoupper($data['destination']),
                'depart_on' => $data['depart_on'],
                'return_on' => $data['return_on'] ?: null,
                'travellers' => $data['travellers'],
            ]);
        } else {
            return back()->withInput()->with('error', 'This client already has a booking in progress.');
        }

        $booking->event('manual', 'Manual quote started by '.$request->user()->name, $booking->routeCodes());
        $messenger->say($client, $booking, 'manual_start', [
            'route' => $booking->routeNames(),
            'date' => $booking->depart_on->format('D j M'),
            'total' => (int) $booking->travellers,
        ]);

        return redirect()->route('bookings.show', $booking)->with('status', 'WhatsApp sent asking for the passport photo.');
    }

    public function ticket(Booking $booking)
    {
        abort_unless($booking->ticket_path && Storage::disk('local')->exists($booking->ticket_path), 404);

        return Storage::disk('local')->download($booking->ticket_path, 'E-ticket_'.$booking->reference.'_'.$booking->pnr.'.pdf');
    }

    public function photo(Booking $booking, Passport $passport, PassportService $passports)
    {
        abort_unless($booking->passports()->whereKey($passport->id)->exists(), 404);
        $bytes = $passports->photo($passport);
        abort_unless($bytes !== null, 404);

        return response($bytes, 200, [
            'Content-Type' => (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'image/jpeg',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function requote(Booking $booking, QuoteService $quotes)
    {
        abort_unless(in_array($booking->status, [BookingStatus::Expired, BookingStatus::AwaitingPayment], true), 409);
        $quotes->quote($booking, $booking->status === BookingStatus::Expired ? 'hold_expired' : 'quote');

        return back()->with('status', 'New quote sent.');
    }

    public function cancel(Booking $booking)
    {
        abort_if((int) $booking->paid_amount > 0, 409, 'The client has paid — refund instead.');
        $booking->update(['status' => BookingStatus::Cancelled]);
        $booking->payments()->where('status', 'open')->update(['status' => 'expired']);
        $booking->setFlag(null)->save();
        $booking->event('cancelled', 'Cancelled by '.request()->user()->name);

        return back()->with('status', 'Booking cancelled.');
    }
}
