<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class DeskController extends Controller
{
    public const HAJJ = ['JED', 'MED'];

    public const DOMESTIC = ['ABV', 'LOS', 'KAN', 'KAD', 'PHC', 'ENU', 'SKO', 'MIU', 'YOL', 'ILR', 'QOW', 'BNI', 'CBQ', 'JOS', 'DKA', 'GMO', 'BCU', 'ABB', 'QUO', 'AKR', 'IBA', 'MXJ'];

    public function index(Request $request)
    {
        $filter = in_array($request->query('route'), ['hajj', 'domestic', 'international'], true) ? $request->query('route') : 'all';

        $scope = fn (Builder $q) => self::routeFilter($q, $filter);

        $open = Booking::query()->open()->where($scope)->with('client')->latest('updated_at')->get();
        $ticketedToday = Booking::query()->where('status', BookingStatus::Ticketed)->whereDate('ticketed_at', today())
            ->where($scope)->with('client')->latest('ticketed_at')->get();

        $columns = [
            'passport' => ['Passport in', '#1E4E8C'],
            'confirming' => ['Confirming details', '#6B7079'],
            'payment' => ['Awaiting payment', '#B45309'],
            'paid' => ['Paid · booking', '#0E5A47'],
            'ticketed' => ['Ticketed', '#13241F'],
        ];
        $pipeline = [];
        foreach ($columns as $key => [$label, $dot]) {
            $items = $key === 'ticketed' ? $ticketedToday : $open->filter(fn (Booking $b) => $b->status->column() === $key)->values();
            $pipeline[$key] = ['label' => $label, 'dot' => $dot, 'items' => $items];
        }

        $inChat = $open->filter(fn ($b) => in_array($b->status, [BookingStatus::CollectingTrip, BookingStatus::AwaitingPassport, BookingStatus::Confirming], true));
        $awaiting = $open->filter(fn ($b) => $b->status === BookingStatus::AwaitingPayment);
        $paid = $open->filter(fn ($b) => in_array($b->status, [BookingStatus::Paid, BookingStatus::FareReview], true));
        $collected = Payment::query()->where('kind', 'charge')->where('status', 'confirmed')->whereDate('paid_at', today())->sum('amount');

        $stats = [
            ['In conversation', $inChat->count(), $this->plural($inChat->filter(fn ($b) => str_starts_with((string) $b->flag, 'Blurry'))->count(), 'waiting on a clearer passport photo', 'Waiting on client replies'), 'plain'],
            ['Awaiting payment', $awaiting->count(), Money::format($awaiting->sum('quote_amount')).' quoted · '.$awaiting->filter(fn ($b) => $b->hold_expires_at?->lt(now()->addMinutes(30)))->count().' expiring soon', 'warn'],
            ['Paid, needs you', $paid->count(), $paid->count() ? 'Oldest paid '.$paid->sortBy('updated_at')->first()->updated_at->diffForHumans() : 'Nothing waiting', 'ok'],
            ['Ticketed today', $ticketedToday->count(), Money::format($collected).' collected today', 'dark'],
        ];

        return view('desk.index', compact('pipeline', 'stats', 'filter'));
    }

    public static function routeFilter(Builder $q, string $filter): Builder
    {
        return match ($filter) {
            'hajj' => $q->whereIn('destination', self::HAJJ),
            'domestic' => $q->whereIn('origin', self::DOMESTIC)->whereIn('destination', self::DOMESTIC),
            'international' => $q->where(fn ($w) => $w->whereNotIn('origin', self::DOMESTIC)->orWhereNotIn('destination', self::DOMESTIC)),
            default => $q,
        };
    }

    private function plural(int $n, string $what, string $none): string
    {
        return $n ? "$n $what" : $none;
    }
}
