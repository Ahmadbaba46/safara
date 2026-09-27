<?php

namespace App\Support;

/**
 * Parses and validates the two-line machine-readable zone (ICAO 9303, TD3)
 * printed at the bottom of a passport's photo page.
 *
 * Line 1: P<NGABELLO<<AISHA<<<<<<<<<<<<<<<<<<<<<<<<<<<
 * Line 2: A09123421<3NGA9106048F3103145<<<<<<<<<<<<<<04
 */
final class Mrz
{
    public static function checkDigit(string $data): int
    {
        $weights = [7, 3, 1];
        $sum = 0;
        $data = strtoupper($data);
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $c = $data[$i];
            if (ctype_digit($c)) {
                $v = (int) $c;
            } elseif ($c >= 'A' && $c <= 'Z') {
                $v = ord($c) - 55; // A=10
            } else {
                $v = 0; // '<'
            }
            $sum += $v * $weights[$i % 3];
        }

        return $sum % 10;
    }

    /** Fix the usual OCR slips: spaces, lower case, « for <. */
    public static function normalise(string $line): string
    {
        $line = strtoupper(trim($line));
        $line = str_replace(['«', ' ', '‹', '|'], ['<', '', '<', ''], $line);

        return $line;
    }

    /**
     * @return array{valid: bool, checks: array<string, bool>, fields: array<string, ?string>}|null
     *         null when the lines are not TD3-shaped at all.
     */
    public static function parse(string $line1, string $line2, ?\DateTimeImmutable $today = null): ?array
    {
        $l1 = self::normalise($line1);
        $l2 = self::normalise($line2);

        if (strlen($l1) !== 44 || strlen($l2) !== 44 || $l1[0] !== 'P') {
            return null;
        }

        $issuing = self::country(substr($l1, 2, 3));
        [$surname, $given] = self::names(substr($l1, 5, 39));

        $number = substr($l2, 0, 9);
        $nationality = self::country(substr($l2, 10, 3));
        $dob = substr($l2, 13, 6);
        $sex = substr($l2, 20, 1);
        $expiry = substr($l2, 21, 6);
        $personal = substr($l2, 28, 14);

        $checks = [
            'number' => self::checkDigit($number) === self::digit($l2[9]),
            'dob' => self::checkDigit($dob) === self::digit($l2[19]),
            'expiry' => self::checkDigit($expiry) === self::digit($l2[27]),
            'personal' => self::checkDigit($personal) === self::digit($l2[42]),
        ];
        $composite = substr($l2, 0, 10).substr($l2, 13, 7).substr($l2, 21, 22);
        $checks['composite'] = self::checkDigit($composite) === self::digit($l2[43]);

        $today ??= new \DateTimeImmutable('today');

        return [
            'valid' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'fields' => [
                'issuing_country' => $issuing,
                'surname' => $surname,
                'given_names' => $given,
                'number' => rtrim($number, '<'),
                'nationality' => $nationality,
                'date_of_birth' => self::date($dob, $today, false),
                'sex' => in_array($sex, ['M', 'F'], true) ? $sex : null,
                'expiry' => self::date($expiry, $today, true),
            ],
        ];
    }

    private static function digit(string $c): int
    {
        return $c === '<' ? 0 : (ctype_digit($c) ? (int) $c : -1);
    }

    private static function country(string $code): string
    {
        // Germany uses "D<<" in the MRZ.
        return $code === 'D<<' ? 'DEU' : str_replace('<', '', $code);
    }

    /** @return array{0: ?string, 1: ?string} */
    private static function names(string $field): array
    {
        $parts = explode('<<', rtrim($field, '<'), 2);
        $surname = trim(str_replace('<', ' ', $parts[0] ?? ''));
        $given = trim(preg_replace('/\s+/', ' ', str_replace('<', ' ', $parts[1] ?? '')));

        return [$surname ?: null, $given ?: null];
    }

    /** YYMMDD → Y-m-d. Birth dates are in the past; expiry dates may be up to ~20 years ahead. */
    private static function date(string $yymmdd, \DateTimeImmutable $today, bool $future): ?string
    {
        if (! preg_match('/^\d{6}$/', $yymmdd)) {
            return null;
        }
        $yy = (int) substr($yymmdd, 0, 2);
        $mm = (int) substr($yymmdd, 2, 2);
        $dd = (int) substr($yymmdd, 4, 2);
        $century = intdiv((int) $today->format('Y'), 100) * 100;
        $year = $century + $yy;
        if ($future) {
            if ($year > (int) $today->format('Y') + 30) {
                $year -= 100;
            }
        } elseif ($year > (int) $today->format('Y')) {
            $year -= 100;
        }
        if (! checkdate($mm, $dd, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $mm, $dd);
    }
}
