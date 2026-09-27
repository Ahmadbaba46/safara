<?php

namespace App\Support;

/**
 * What to do once the client has paid and we've re-checked the live fare.
 *
 *  - issue:  the fare fits (or the rise is small enough to absorb) and
 *            auto-issuing is allowed right now
 *  - hold:   the fare fits, but a person must press "Issue ticket"
 *            (auto-issue off, or overnight pause)
 *  - review: the fare rose by more than we absorb — a person decides
 */
final class FareDecision
{
    public const ISSUE = 'issue';
    public const HOLD = 'hold';
    public const REVIEW = 'review';

    public function __construct(
        public readonly string $action,
        public readonly int $difference, // live fare − amount paid (negative = room to spare)
        public readonly bool $absorbed,
        public readonly string $reason,
    ) {}

    public static function decide(
        int $paid,
        int $liveFare,
        bool $autoIssue,
        bool $absorb,
        int $absorbLimit,
        bool $paused,
    ): self {
        $diff = $liveFare - $paid;

        if ($diff > 0 && ! ($absorb && $diff <= $absorbLimit)) {
            return new self(self::REVIEW, $diff, false, 'Fare rose by '.Money::format($diff).', above the absorb limit');
        }

        $absorbed = $diff > 0;
        $why = $absorbed ? 'Fare rose by '.Money::format($diff).', absorbed' : 'Fare within what the client paid';

        if (! $autoIssue) {
            return new self(self::HOLD, $diff, $absorbed, $why.' · auto-issue is off');
        }
        if ($paused) {
            return new self(self::HOLD, $diff, $absorbed, $why.' · overnight pause');
        }

        return new self(self::ISSUE, $diff, $absorbed, $why);
    }

    /** Is "now" inside the overnight window? Handles windows that cross midnight. */
    public static function inWindow(string $now, string $start, string $end): bool
    {
        if ($start === $end) {
            return false;
        }

        return $start < $end
            ? ($now >= $start && $now < $end)
            : ($now >= $start || $now < $end);
    }
}
