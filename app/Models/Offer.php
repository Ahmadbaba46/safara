<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Offer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'depart_on' => 'date',
            'segments' => 'array',
            'passenger_ids' => 'array',
            'expires_at' => 'datetime',
            'selected' => 'boolean',
            'original_amount' => 'decimal:2',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
