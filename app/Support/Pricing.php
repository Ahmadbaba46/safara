<?php

namespace App\Support;

/**
 * Turns a fare into the price the client pays.
 *
 * quote = the fare plus whichever is larger of the markup % and the minimum
 * margin, then rounded UP to the nearest round_to (0 = no rounding).
 */
final class Pricing
{
    public function __construct(
        private float $markupPercent,
        private int $minMargin,
        private int $roundTo,
    ) {}

    public static function fromSettings(array $s): self
    {
        return new self((float) ($s['markup_percent'] ?? 0), (int) ($s['min_margin'] ?? 0), (int) ($s['round_to'] ?? 0));
    }

    public function quote(int $fare): int
    {
        $withMarkup = (int) ceil($fare * (1 + $this->markupPercent / 100));
        $price = max($withMarkup, $fare + $this->minMargin);

        if ($this->roundTo > 0) {
            $price = (int) (ceil($price / $this->roundTo) * $this->roundTo);
        }

        return $price;
    }
}
