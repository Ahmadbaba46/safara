<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Passport;
use App\Services\PassportService;
use Illuminate\Http\Request;

class ClientsController extends Controller
{
    public function index(Request $request)
    {
        $term = trim((string) $request->query('q'));
        $clients = Client::query()
            ->withCount(['bookings as trips_count' => fn ($q) => $q->where('status', 'ticketed')])
            ->withMax('bookings', 'updated_at')
            ->when($term, function ($q) use ($term) {
                $digits = preg_replace('/\D/', '', $term);
                $q->where(fn ($w) => $w->where('name', 'like', "%$term%")->when(strlen($digits) >= 4, fn ($x) => $x->orWhere('phone', 'like', "%$digits%")));
            })
            ->orderByDesc('bookings_max_updated_at')->orderBy('name')
            ->paginate(20)->withQueryString();

        $current = $request->query('c')
            ? Client::query()->find($request->query('c'))
            : $clients->first();

        $data = ['clients' => $clients, 'current' => $current, 'term' => $term];

        if ($current) {
            $current->load(['bookings', 'passports']);
            $data['trips'] = $current->bookings;
            $data['totalPaid'] = $current->bookings->filter(fn ($b) => $b->status === \App\Enums\BookingStatus::Ticketed)->sum('paid_amount');
            $data['next'] = $current->bookings->filter(fn ($b) => $b->depart_on?->isFuture() && in_array($b->status->value, ['ticketed', 'paid', 'awaiting_payment'], true))->sortBy('depart_on')->first();
            $data['passports'] = $current->passports->sortByDesc('id')->values();
        }

        return view('clients.index', $data);
    }

    public function notes(Request $request, Client $client)
    {
        $client->update($request->validate(['notes' => 'nullable|string|max:5000']));

        return back()->with('status', 'Notes saved.');
    }

    public function language(Request $request, Client $client)
    {
        $client->update($request->validate(['language' => 'required|in:en,ha']));

        return back()->with('status', 'Chat language updated.');
    }

    public function destroyPassport(Client $client, Passport $passport, PassportService $passports)
    {
        abort_unless($passport->client_id === $client->id, 404);
        $passports->wipe($passport);

        return back()->with('status', 'Passport data and photo deleted.');
    }

    public function photo(Client $client, Passport $passport, PassportService $passports)
    {
        abort_unless($passport->client_id === $client->id, 404);
        $bytes = $passports->photo($passport);
        abort_unless($bytes !== null, 404);

        return response($bytes, 200, [
            'Content-Type' => (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'image/jpeg',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
