<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\FareDecision;
use App\Support\Pricing;
use Illuminate\Support\Facades\Schema;

/**
 * Live business settings, edited on the desk's Settings screen.
 * Anything not saved yet falls back to config('safara.defaults').
 */
class Settings
{
    private ?array $cache = null;

    public function all(): array
    {
        if ($this->cache === null) {
            $saved = Schema::hasTable('settings') ? Setting::query()->pluck('value', 'key')->all() : [];
            $this->cache = array_merge(config('safara.defaults'), $saved);
        }

        return $this->cache;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $this->cache = null;
    }

    public function pricing(): Pricing
    {
        return Pricing::fromSettings($this->all());
    }

    public function holdMinutes(): int
    {
        return max(15, (int) $this->get('hold_minutes', 120));
    }

    public function holdLabel(): string
    {
        $m = $this->holdMinutes();
        if ($m % 60 === 0) {
            $h = intdiv($m, 60);

            return $h.' '.($h === 1 ? 'hour' : 'hours');
        }

        return $m.' minutes';
    }

    /** Convert a provider amount to whole naira using the configured rates. */
    public function toNaira(float $amount, string $currency): int
    {
        $rates = $this->get('fx_rates', []);
        $rate = $rates[strtoupper($currency)] ?? null;
        if ($rate === null) {
            throw new \RuntimeException("No exchange rate for $currency. Add it under Settings → Pricing.");
        }

        return (int) ceil($amount * (float) $rate);
    }

    public function nightPauseActive(?\DateTimeInterface $now = null): bool
    {
        if (! $this->get('night_pause')) {
            return false;
        }
        $now ??= now(config('safara.timezone'));

        return FareDecision::inWindow($now->format('H:i'), (string) $this->get('night_start', '22:00'), (string) $this->get('night_end', '06:00'));
    }

    public function forget(): void
    {
        $this->cache = null;
    }
}
