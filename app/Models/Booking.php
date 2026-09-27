<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Support\Iata;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

class Booking extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'depart_on' => 'date',
            'return_on' => 'date',
            'quoted_at' => 'datetime',
            'hold_expires_at' => 'datetime',
            'ticketed_at' => 'datetime',
            'bot_paused' => 'boolean',
            'state' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Booking $booking) {
            if (! $booking->reference) {
                $next = (int) static::query()->max('id') + 2401;
                do {
                    $ref = 'SF-'.$next++;
                } while (static::query()->where('reference', $ref)->exists());
                $booking->reference = $ref;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    // ---- relations ------------------------------------------------------

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function passports(): BelongsToMany
    {
        return $this->belongsToMany(Passport::class)->withPivot('position')->withTimestamps()->orderByPivot('position');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->oldest('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BookingEvent::class)->oldest('id');
    }

    // ---- scopes -----------------------------------------------------------

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', BookingStatus::open());
    }

    /** The booking a new WhatsApp message from this client belongs to. */
    public static function currentFor(Client $client): ?self
    {
        return static::query()->where('client_id', $client->id)
            ->whereIn('status', BookingStatus::open())
            ->latest('id')->first();
    }

    // ---- helpers ----------------------------------------------------------

    public function event(string $type, string $title, ?string $detail = null): BookingEvent
    {
        return $this->events()->create(['type' => $type, 'title' => $title, 'detail' => $detail]);
    }

    public function stateGet(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->state ?? [], $key, $default);
    }

    public function stateSet(string $key, mixed $value): static
    {
        $state = $this->state ?? [];
        Arr::set($state, $key, $value);
        $this->state = $state;

        return $this;
    }

    public function stateForget(string $key): static
    {
        $state = $this->state ?? [];
        Arr::forget($state, $key);
        $this->state = $state;

        return $this;
    }

    public function setFlag(?string $text, ?string $tone = 'info'): static
    {
        $this->flag = $text;
        $this->flag_tone = $text ? $tone : null;

        return $this;
    }

    public function routeCodes(): string
    {
        return $this->origin && $this->destination ? $this->origin.' → '.$this->destination : '—';
    }

    public function routeNames(): string
    {
        if (! $this->origin || ! $this->destination) {
            return '—';
        }

        return Iata::city($this->origin).' → '.Iata::city($this->destination);
    }

    public function routeLong(): string
    {
        if (! $this->origin || ! $this->destination) {
            return 'Trip details not complete';
        }

        return Iata::city($this->origin).' ('.$this->origin.') → '.Iata::city($this->destination).' ('.$this->destination.')';
    }

    public function travellersLabel(): string
    {
        $n = (int) $this->travellers;

        return $n ? $n.' '.($n === 1 ? 'traveller' : 'travellers') : '—';
    }

    public function dateLabel(): string
    {
        return $this->depart_on ? $this->depart_on->format('D j M') : '—';
    }

    public function tripLine(): string
    {
        $parts = [$this->routeLong()];
        if ($this->depart_on) {
            $parts[] = $this->depart_on->format('D j M');
        }
        $parts[] = $this->return_on ? 'Return '.$this->return_on->format('D j M') : 'One way';
        $parts[] = $this->travellersLabel();
        $parts[] = ucfirst(str_replace('_', ' ', $this->cabin_class));

        return implode(' · ', $parts);
    }

    public function amountLabel(): string
    {
        return $this->quote_amount ? Money::format($this->quote_amount) : '—';
    }

    public function margin(): ?int
    {
        $fare = $this->ticketed_fare ?? $this->fare_amount;

        return $this->quote_amount && $fare ? (int) $this->quote_amount - (int) $fare : null;
    }

    public function selectedOffer(): ?Offer
    {
        return $this->offers()->where('selected', true)->latest('id')->first();
    }

    public function latestOffers()
    {
        $batch = $this->offers()->latest('id')->value('batch');

        return $batch ? $this->offers()->where('batch', $batch)->orderBy('amount')->get() : collect();
    }

    public function openPayment(): ?Payment
    {
        return $this->payments()->where('kind', 'charge')->where('status', 'open')->first();
    }

    public function needsPerson(): bool
    {
        return $this->bot_paused;
    }
}
