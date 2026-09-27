<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

class Passport extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['surname', 'given_names', 'number', 'date_of_birth'];

    protected function casts(): array
    {
        return [
            'surname' => 'encrypted',
            'given_names' => 'encrypted',
            'number' => 'encrypted',
            'date_of_birth' => 'encrypted',
            'expiry' => 'date',
            'confidence' => 'array',
            'mrz_valid' => 'boolean',
            'confirmed_at' => 'datetime',
            'consent_at' => 'datetime',
            'delete_after' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Passport $p) {
            $p->number_last3 = $p->number ? substr($p->number, -3) : null;
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function bookings(): BelongsToMany
    {
        return $this->belongsToMany(Booking::class)->withPivot('position')->withTimestamps();
    }

    public function fullName(): string
    {
        return trim(($this->given_names ?? '').' '.($this->surname ?? ''));
    }

    public function firstName(): string
    {
        $first = explode(' ', trim($this->given_names ?? ''))[0] ?? '';

        return $first !== '' ? mb_convert_case($first, MB_CASE_TITLE) : '';
    }

    public function maskedNumber(): string
    {
        $n = (string) $this->number;
        if (strlen($n) < 6) {
            return $n === '' ? '—' : '•••'.$this->number_last3;
        }

        return substr($n, 0, 3).'•••'.substr($n, -3);
    }

    public function dob(): ?Carbon
    {
        return $this->date_of_birth ? Carbon::parse($this->date_of_birth) : null;
    }

    public function ageOn(Carbon $date): ?int
    {
        $dob = $this->dob();

        return $dob ? (int) floor(abs($dob->diffInYears($date))) : null;
    }

    /** Per-field confidence 0–100, or null when unknown. */
    public function confidenceFor(string $field): ?int
    {
        $c = $this->confidence[$field] ?? null;

        return $c === null ? null : (int) $c;
    }

    public function hasPhoto(): bool
    {
        return (bool) $this->image_path;
    }
}
