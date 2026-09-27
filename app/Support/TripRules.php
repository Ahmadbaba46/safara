<?php

namespace App\Support;

/**
 * Understands trip requests without any AI: "Kano to Jeddah, 12 October, just me",
 * "daga Abuja zuwa Legas gobe mutum biyu", "KAN-DXB 18/10 return 25/10 2 adults".
 * The Anthropic parser is better at messy messages; this one is the fallback.
 */
final class TripRules
{
    private const MONTHS = [
        'january' => 1, 'jan' => 1, 'february' => 2, 'feb' => 2, 'march' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4, 'may' => 5, 'june' => 6, 'jun' => 6, 'july' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8, 'september' => 9, 'sept' => 9, 'sep' => 9, 'october' => 10, 'oct' => 10,
        'november' => 11, 'nov' => 11, 'december' => 12, 'dec' => 12,
    ];

    private const NUMBERS = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7,
        'eight' => 8, 'nine' => 9, 'ten' => 10,
        'daya' => 1, 'biyu' => 2, 'uku' => 3, 'hudu' => 4, 'huɗu' => 4, 'biyar' => 5, 'shida' => 6,
    ];

    private const HAUSA_HINTS = ['sannu', 'ina son', 'zuwa', 'daga', 'gobe', 'jibi', 'don allah', 'na gode', 'mutum', 'mutane', 'jirgi', 'tikiti', 'ni kadai', 'nawa', 'yaya', 'barka'];

    private \DateTimeImmutable $today;

    public function __construct(?\DateTimeImmutable $today = null)
    {
        $this->today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);
    }

    public function parse(string $text): TripRequest
    {
        $lower = mb_strtolower($text);
        $req = new TripRequest;

        foreach (self::HAUSA_HINTS as $hint) {
            if (str_contains($lower, $hint)) {
                $req->language = 'ha';
                break;
            }
        }

        [$req->origin, $req->destination] = $this->places($text);
        [$req->departOn, $req->returnOn] = $this->dates($lower);
        $req->travellers = $this->travellers($lower);

        return $req;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function places(string $text): array
    {
        $found = Iata::findAll($text);
        if (! $found) {
            return [null, null];
        }
        $lower = ' '.mb_strtolower($text);
        $origin = $destination = null;

        foreach ($found as $f) {
            $start = max(0, $f['pos'] - 12);
            $before = substr($lower, $start, $f['pos'] - $start);
            if (preg_match('/(from|daga|leaving|departing)\s*$/u', trim($before)) && ! $origin) {
                $origin = $f['code'];
            } elseif (preg_match('/(to|zuwa|->|→|-|–)\s*$/u', trim($before)) && ! $destination) {
                $destination = $f['code'];
            }
        }

        $codes = array_values(array_unique(array_column($found, 'code')));
        foreach ($codes as $code) {
            if ($code === $origin || $code === $destination) {
                continue;
            }
            if (! $origin && count($codes) > 1) {
                $origin = $code;
            } elseif (! $destination) {
                $destination = $code;
            }
        }

        // A single place with no direction word: most people name where they're going.
        if (count($codes) === 1 && ! $destination && $origin && ! preg_match('/(from|daga)\s/u', $lower)) {
            [$origin, $destination] = [null, $origin];
        }

        return [$origin, $destination];
    }

    /** @return array{0: ?string, 1: ?string} */
    private function dates(string $lower): array
    {
        $hits = []; // [pos, Y-m-d]
        $t = $this->today;

        $relative = [
            '/\b(today|yau)\b/u' => 0,
            '/\b(tomorrow|tmrw|tmr|gobe)\b/u' => 1,
            '/\b(day after tomorrow|jibi)\b/u' => 2,
        ];
        foreach ($relative as $re => $days) {
            if (preg_match($re, $lower, $m, PREG_OFFSET_CAPTURE)) {
                $hits[] = [$m[0][1], $t->modify("+$days day")->format('Y-m-d')];
            }
        }

        $monthRe = implode('|', array_keys(self::MONTHS));

        // 12 October / 12th Oct 2026
        if (preg_match_all('/\b(\d{1,2})(?:st|nd|rd|th)?\s*(?:of\s+)?('.$monthRe.')\.?(?:\s*,?\s*(\d{4}))?\b/u', $lower, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $x) {
                $hits[] = [$x[0][1], $this->ymd((int) $x[1][0], self::MONTHS[$x[2][0]], isset($x[3]) ? (int) $x[3][0] : null)];
            }
        }
        // October 12 / Oct 12th, 2026
        if (preg_match_all('/\b('.$monthRe.')\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:\s*,?\s*(\d{4}))?\b/u', $lower, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $x) {
                $hits[] = [$x[0][1], $this->ymd((int) $x[2][0], self::MONTHS[$x[1][0]], isset($x[3]) ? (int) $x[3][0] : null)];
            }
        }
        // 2026-10-12
        if (preg_match_all('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $lower, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $x) {
                $hits[] = [$x[0][1], $this->ymd((int) $x[3][0], (int) $x[2][0], (int) $x[1][0])];
            }
        }
        // 12/10, 12/10/26, 12-10-2026 (day first, as written in Nigeria)
        if (preg_match_all('/(?<![\d-])(\d{1,2})[\/.-](\d{1,2})(?:[\/.-](\d{2,4}))?(?![\d-])/', $lower, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $x) {
                $year = isset($x[3]) ? (int) $x[3][0] : null;
                if ($year !== null && $year < 100) {
                    $year += 2000;
                }
                $hits[] = [$x[0][1], $this->ymd((int) $x[1][0], (int) $x[2][0], $year)];
            }
        }

        $hits = array_values(array_filter($hits, fn ($h) => $h[1] !== null));
        usort($hits, fn ($a, $b) => $a[0] <=> $b[0]);
        // Drop duplicates found by two patterns at the same place.
        $dates = [];
        $lastPos = -100;
        foreach ($hits as [$pos, $date]) {
            if ($pos - $lastPos < 3) {
                continue;
            }
            $dates[] = [$pos, $date];
            $lastPos = $pos;
        }

        if (! $dates) {
            return [null, null];
        }

        $depart = $dates[0][1];
        $return = null;
        if (count($dates) > 1) {
            $between = mb_substr($lower, 0, $dates[1][0]);
            if (preg_match('/\b(return|returning|back|round|dawowa|komawa|till|until)\b/u', $between) && $dates[1][1] >= $depart) {
                $return = $dates[1][1];
            }
        }

        return [$depart, $return];
    }

    private function ymd(int $day, int $month, ?int $year): ?string
    {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }
        $y = $year ?? (int) $this->today->format('Y');
        if (! checkdate($month, $day, $y)) {
            return null;
        }
        $date = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $month, $day));
        if ($year === null && $date < $this->today) {
            $date = $date->modify('+1 year');
        }
        if ($date < $this->today) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    private function travellers(string $lower): ?int
    {
        if (preg_match('/\b(just me|only me|myself|me alone|alone|one person|1 person|1 adult|ni kadai|ni kaɗai|single ticket)\b/u', $lower)) {
            return 1;
        }

        $num = '(\d{1,2}|'.implode('|', array_keys(self::NUMBERS)).')';
        $patterns = [
            '/\b'.$num.'\s*(adults?|people|persons?|passengers?|pax|travell?ers?|tickets?|of us)\b/u',
            '/\b(?:mutum|mutane)\s*'.$num.'\b/u',
            '/\bwe are\s*'.$num.'\b/u',
            '/\bfor\s*'.$num.'\b(?!\s*(?:am|pm|days?|nights?|weeks?|hours?|:))/u',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $lower, $m)) {
                $n = ctype_digit($m[1]) ? (int) $m[1] : (self::NUMBERS[$m[1]] ?? null);
                if ($n && $n <= 9) {
                    return $n;
                }
            }
        }

        if (preg_match('/\bme and my (wife|husband|friend|brother|sister|son|daughter|mother|father|mum|dad|mom)\b/u', $lower)
            && ! preg_match('/\band\b.*\band\b/u', substr($lower, strpos($lower, 'me and my')))) {
            return 2;
        }

        return null;
    }
}
