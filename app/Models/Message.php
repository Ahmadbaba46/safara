<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function isInbound(): bool
    {
        return $this->direction === 'in';
    }

    /** Button / list labels attached to an outbound message. */
    public function options(): array
    {
        $p = $this->payload ?? [];

        return array_values(array_map(fn ($o) => $o['title'] ?? '', $p['buttons'] ?? $p['rows'] ?? []));
    }
}
