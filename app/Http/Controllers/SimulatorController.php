<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Integrations\Flights\FakeFlightSearch;
use App\Models\Booking;
use App\Models\Client;
use App\Services\Conversation;
use App\Services\InboundMessage;
use App\Services\PassportService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Rehearse the whole WhatsApp flow from the desk, as if you were the client.
 * Only available when SAFARA_SIMULATOR=true (never in production).
 */
class SimulatorController extends Controller
{
    public function __construct()
    {
        abort_unless(config('safara.simulator') && ! app()->environment('production'), 404);
    }

    public function index(Request $request)
    {
        $phone = preg_replace('/\D/', '', (string) $request->query('phone', session('sim_phone', '2348030004417')));
        session(['sim_phone' => $phone]);
        $client = Client::query()->where('phone', $phone)->first();
        $messages = $client ? $client->messages()->oldest('id')->get() : collect();
        $booking = $client ? Booking::currentFor($client) ?? $client->bookings()->first() : null;

        return view('simulator.index', [
            'phone' => $phone,
            'client' => $client,
            'messages' => $messages,
            'booking' => $booking,
            'lastOut' => $messages->where('direction', 'out')->last(),
            'bump' => Cache::get(FakeFlightSearch::BUMP_KEY),
            'fake' => [
                'whatsapp' => config('safara.drivers.whatsapp') === 'fake',
                'flights' => config('safara.drivers.flights') === 'fake',
                'passport' => config('safara.drivers.passport_reader') === 'fake',
                'payments' => config('safara.drivers.payments') === 'fake',
            ],
        ]);
    }

    public function send(Request $request, Conversation $conversation)
    {
        $data = $request->validate([
            'phone' => 'required|string',
            'kind' => 'required|in:text,photo,blurry,reply',
            'text' => 'nullable|string|max:1000',
            'reply_id' => 'nullable|string|max:100',
            'name' => 'nullable|string|max:60',
            'photo' => 'nullable|image|max:8192',
        ]);
        $phone = preg_replace('/\D/', '', $data['phone']);
        $profile = $data['name'] ?? 'Aisha';

        $in = match ($data['kind']) {
            'text' => new InboundMessage($phone, 'text', (string) ($data['text'] ?? ''), waId: 'sim.'.Str::random(12), profileName: $profile),
            'reply' => new InboundMessage($phone, 'reply', $data['text'] ?? null, replyId: $data['reply_id'], waId: 'sim.'.Str::random(12), profileName: $profile),
            'photo', 'blurry' => $this->photo($request, $phone, $data, $profile),
        };

        $conversation->handle($in);

        return redirect()->route('simulator', ['phone' => $phone]);
    }

    private function photo(Request $request, string $phone, array $data, string $profile): InboundMessage
    {
        $mediaId = 'sim'.Str::random(16);
        if ($request->hasFile('photo')) {
            $bytes = $request->file('photo')->get();
        } else {
            $bytes = $data['kind'] === 'blurry' ? 'BLURRY' : 'SAMPLE';
        }
        if (! empty($data['text']) && preg_match('/^[A-Za-z]+ [A-Za-z ]+$/', $data['text'])) {
            $bytes .= ' NAME:'.strtoupper($data['text']);
        }
        Storage::disk('local')->put('simulator/'.$mediaId, $bytes);

        return new InboundMessage($phone, 'image', null, mediaId: $mediaId, mime: 'image/jpeg', waId: 'sim.'.Str::random(12), profileName: $profile);
    }

    public function control(Request $request, PaymentService $payments)
    {
        $action = $request->validate(['action' => 'required|in:bump,empty,expire,tick,reset'])['action'];
        $phone = session('sim_phone');

        switch ($action) {
            case 'bump':
                // "amount" = how far above the quote the fare should land after payment.
                $over = (int) $request->input('amount', 38000);
                $client = Client::query()->where('phone', $phone)->first();
                $booking = $client ? Booking::currentFor($client) : null;
                $pax = max(1, (int) ($booking?->travellers ?? 1));
                $room = $booking && $booking->quote_amount && $booking->fare_amount ? $booking->quote_amount - $booking->fare_amount : 0;
                FakeFlightSearch::bumpNextSearch((int) ceil(($room + $over) / $pax));
                $msg = 'After payment, the fare will come back '.\App\Support\Money::format($over).' above the quote.';
                break;
            case 'empty':
                Cache::put(FakeFlightSearch::EMPTY_KEY, true, now()->addHour());
                $msg = 'The next flight search will find nothing.';
                break;
            case 'expire':
                $client = Client::query()->where('phone', $phone)->first();
                $booking = $client ? Booking::currentFor($client) : null;
                $booking?->payments()->where('status', 'open')->update(['expires_at' => now()->subMinute()]);
                $booking?->update(['hold_expires_at' => now()->subMinute()]);
                $payments->expireHolds();
                $msg = 'The price hold has ended.';
                break;
            case 'tick':
                Artisan::call('safara:tick');
                $msg = trim(Artisan::output());
                break;
            case 'reset':
                $client = Client::query()->where('phone', $phone)->first();
                if ($client) {
                    foreach ($client->passports as $p) {
                        app(PassportService::class)->wipe($p);
                    }
                    $client->delete();
                }
                $msg = 'Chat cleared.';
                break;
        }

        return back()->with('status', $msg);
    }
}
