<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Payment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Payment $p) {
            $p->token ??= Str::random(40);
            if (! $p->reference) {
                $prefix = $p->kind === 'refund' ? 'REF' : 'PAY';
                do {
                    $ref = $prefix.'-'.random_int(10000, 99999);
                } while (static::query()->where('reference', $ref)->exists());
                $p->reference = $ref;
            }
        });
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function url(): string
    {
        return route('pay.show', $this->token);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open' && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'open' => $this->expires_at ? 'Link open · '.self::left($this->expires_at).' left' : 'Link open',
            'confirmed' => 'Confirmed',
            'failed' => 'Failed',
            'expired' => 'Expired',
            'due' => 'Refund due',
            'sent' => 'Refund sent',
            default => ucfirst($this->status),
        };
    }

    public function tone(): string
    {
        return match ($this->status) {
            'confirmed' => 'ok',
            'open' => 'warn',
            'due', 'failed' => 'bad',
            default => 'mute',
        };
    }

    public function amountLabel(): string
    {
        return Money::format($this->amount);
    }

    public static function left(\DateTimeInterface $until): string
    {
        $mins = max(0, (int) ceil((($until->getTimestamp()) - time()) / 60));
        if ($mins < 60) {
            return $mins.' min';
        }

        return intdiv($mins, 60).'h '.str_pad((string) ($mins % 60), 2, '0', STR_PAD_LEFT).'m';
    }
}
