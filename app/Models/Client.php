<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Client extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_inbound_at' => 'datetime'];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class)->latest('id');
    }

    public function passports(): HasMany
    {
        return $this->hasMany(Passport::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** The newest passport the client agreed to keep that is still valid. */
    public function savedPassport(): HasOne
    {
        return $this->hasOne(Passport::class)->ofMany(['id' => 'max'], function ($q) {
            $q->whereNotNull('consent_at')
                ->whereNotNull('confirmed_at')
                ->where('expiry', '>', now()->toDateString());
        });
    }

    public function displayName(): string
    {
        return $this->name ?: $this->phoneLabel();
    }

    public function isApp(): bool
    {
        return $this->channel === 'app';
    }

    /** The number to show for this client. App clients have a placeholder phone, so use what they typed. */
    public function phoneLabel(): string
    {
        if ($this->isApp()) {
            return $this->contact_phone ? '+'.$this->contact_phone : 'Safara app';
        }

        return '+'.$this->phone;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->displayName())) ?: [];
        $letters = array_map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)), array_slice($parts, 0, 2));

        return implode('', $letters) ?: '?';
    }

    public function maskedPhone(): string
    {
        if ($this->isApp()) {
            return $this->contact_phone ? '+'.$this->contact_phone : 'Safara app';
        }
        $p = $this->phone;
        if (strlen($p) < 10) {
            return '+'.$p;
        }

        return '+'.substr($p, 0, 3).' '.substr($p, 3, 3).' ••• '.substr($p, -4);
    }

    /** Inside WhatsApp's 24-hour customer service window? */
    public function inServiceWindow(): bool
    {
        if ($this->isApp()) {
            return true; // no 24-hour rule inside our own app
        }

        return $this->last_inbound_at !== null && $this->last_inbound_at->gt(now()->subHours(24));
    }
}
