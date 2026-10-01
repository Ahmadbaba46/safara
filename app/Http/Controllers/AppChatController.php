<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Message;
use App\Services\Conversation;
use App\Services\InboundMessage;
use App\Services\PassportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The Safara app: the same chat clients used to have on WhatsApp, as a
 * standalone web app. Messages go through the same Conversation engine and
 * land in the same messages table, so the desk sees them like any other chat.
 *
 * A device is identified by a long-lived cookie holding a random token; only
 * its hash is stored on the client.
 */
class AppChatController extends Controller
{
    private const COOKIE = 'safara_app';

    public function __construct()
    {
        abort_unless(config('safara.app.enabled'), 404);
    }

    public function show(Request $request)
    {
        return view('app.chat', ['client' => $this->client($request), 'brand' => config('safara.brand')]);
    }

    public function start(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'phone' => 'nullable|string|max:25',
            'language' => 'required|in:en,ha',
        ]);
        $digits = preg_replace('/\D/', '', (string) ($data['phone'] ?? ''));
        if ($digits !== '' && (strlen($digits) < 8 || strlen($digits) > 15)) {
            return response()->json(['message' => 'That phone number looks too short or too long.', 'errors' => ['phone' => ['Check the number.']]], 422);
        }

        // Nigerian numbers are often typed locally (0803…); store them in international form.
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = '234'.substr($digits, 1);
        }

        $client = $this->client($request);
        if (! $client) {
            $token = Str::random(48);
            $client = Client::query()->create([
                'phone' => $this->placeholderPhone(),
                'channel' => 'app',
                'app_token' => hash('sha256', $token),
            ]);
            Cookie::queue(Cookie::make(self::COOKIE, $token, 60 * 24 * 365 * 3, '/', null, $request->isSecure(), true, false, 'lax'));
        }
        $client->fill(['name' => $data['name'], 'contact_phone' => $digits ?: null, 'language' => $data['language']])->save();

        return response()->json(['ok' => true]);
    }

    public function messages(Request $request)
    {
        $client = $this->client($request);
        if (! $client) {
            return response()->json(['messages' => [], 'booking' => null]);
        }

        return response()->json($this->snapshot($client, (int) $request->query('after', 0)));
    }

    public function send(Request $request, Conversation $conversation)
    {
        $client = $this->client($request);
        abort_unless($client, 403);

        $data = $request->validate([
            'kind' => 'required|in:text,reply,photo',
            'text' => 'nullable|string|max:1000',
            'reply_id' => 'nullable|string|max:100',
            'photo' => 'nullable|image|max:10240',
            'after' => 'nullable|integer',
        ]);

        $from = $client->phone;
        $waId = 'app.in.'.Str::random(16);

        $in = match ($data['kind']) {
            'text' => new InboundMessage($from, 'text', trim((string) ($data['text'] ?? '')), waId: $waId),
            'reply' => new InboundMessage($from, 'reply', $data['text'] ?? null, replyId: $data['reply_id'] ?? null, waId: $waId),
            'photo' => $this->photo($request, $from, $data, $waId),
        };
        if ($in->type === 'text' && $in->text === '') {
            return response()->json(['message' => 'Type a message first.'], 422);
        }

        $conversation->handle($in);

        return response()->json($this->snapshot($client->fresh(), (int) ($data['after'] ?? 0)));
    }

    public function document(Request $request, Message $message)
    {
        $client = $this->client($request);
        abort_unless($client && $message->client_id === $client->id && $message->type === 'document', 404);

        $path = $message->payload['path'] ?? null;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $message->payload['filename'] ?? 'e-ticket.pdf', ['Content-Type' => 'application/pdf']);
    }

    /** "Delete my data": passports, chat and bookings for this device. */
    public function forget(Request $request, PassportService $passports)
    {
        $client = $this->client($request);
        if ($client) {
            foreach ($client->passports as $p) {
                $passports->wipe($p);
            }
            $client->delete();
        }
        Cookie::queue(Cookie::forget(self::COOKIE));

        return response()->json(['ok' => true]);
    }

    private function client(Request $request): ?Client
    {
        $token = $request->cookie(self::COOKIE);
        if (! is_string($token) || $token === '') {
            return null;
        }

        return Client::query()->where('app_token', hash('sha256', $token))->first();
    }

    private function photo(Request $request, string $from, array $data, string $waId): InboundMessage
    {
        abort_unless($request->hasFile('photo'), 422, 'Choose a photo first.');
        $mediaId = 'app_'.Str::random(24);
        Storage::disk('local')->put('app-uploads/'.$mediaId, $request->file('photo')->get());

        return new InboundMessage($from, 'image', $data['text'] ?? null, mediaId: $mediaId, mime: $request->file('photo')->getMimeType() ?: 'image/jpeg', waId: $waId);
    }

    private function placeholderPhone(): string
    {
        do {
            $phone = '99'.str_pad((string) random_int(0, 9999999999999), 13, '0', STR_PAD_LEFT);
        } while (Client::query()->where('phone', $phone)->exists());

        return $phone;
    }

    /** Messages newer than $after, plus what the header and composer need. */
    private function snapshot(Client $client, int $after): array
    {
        $all = $client->messages()->oldest('id')->get();
        $lastOut = $all->where('direction', 'out')->last();
        $answered = $lastOut && $all->where('direction', 'in')->where('id', '>', $lastOut->id)->isNotEmpty();
        $booking = Booking::currentFor($client) ?? $client->bookings()->first();

        return [
            'messages' => $all->where('id', '>', $after)->values()->map(fn (Message $m) => $this->present($m, $lastOut && ! $answered && $m->id === $lastOut->id))->all(),
            'booking' => $booking ? [
                'reference' => $booking->reference,
                'status' => $booking->status->label(),
                'trip' => $booking->tripLine(),
            ] : null,
            'paused' => (bool) $booking?->bot_paused,
        ];
    }

    private function present(Message $m, bool $live): array
    {
        $p = $m->payload ?? [];

        return [
            'id' => $m->id,
            'dir' => $m->direction,
            'type' => $m->type,
            'body' => $m->type === 'image' && $m->body === 'Photo' ? '' : (string) $m->body,
            'time' => $m->created_at->format('H:i'),
            'day' => $m->created_at->isToday() ? 'Today' : $m->created_at->format('D j M'),
            'from' => $m->direction === 'out' && $m->sent_by && $m->sent_by !== 'bot' ? $m->sent_by : null,
            'failed' => $m->status === 'failed',
            'buttons' => array_values($p['buttons'] ?? []),
            'rows' => array_values($p['rows'] ?? []),
            'label' => $p['label'] ?? null,
            'url' => $m->type === 'cta' ? ($p['url'] ?? null) : null,
            'file' => $m->type === 'document' ? ['name' => $p['filename'] ?? 'e-ticket.pdf', 'url' => route('app.document', $m)] : null,
            'live' => $live,
        ];
    }
}
