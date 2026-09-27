<?php

namespace App\Support;

final class Money
{
    /** 685000 → "₦685,000" (whole naira). */
    public static function format(int|float|null $amount): string
    {
        if ($amount === null) {
            return '—';
        }
        $sign = $amount < 0 ? '−' : '';

        return $sign.'₦'.number_format(abs((int) round($amount)));
    }

    /** Signed, for margins: "+₦13,200" / "−₦21,600". */
    public static function signed(int|float $amount): string
    {
        return ($amount >= 0 ? '+' : '').self::format($amount);
    }

    /** "1,310,000" or "₦1,310,000" or "1310000" → 1310000 */
    public static function parse(string|int|float|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_int($value) || is_float($value)) {
            return (int) round($value);
        }

        return (int) preg_replace('/[^0-9]/', '', $value);
    }
}
