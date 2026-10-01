<?php

namespace App\Enums;

enum BookingStatus: string
{
    case CollectingTrip = 'collecting_trip';
    case AwaitingPassport = 'awaiting_passport';
    case Confirming = 'confirming';
    case AwaitingQuote = 'awaiting_quote';
    case AwaitingPayment = 'awaiting_payment';
    case Expired = 'expired';
    case Paid = 'paid';
    case FareReview = 'fare_review';
    case AwaitingChoice = 'awaiting_choice';
    case AwaitingDifference = 'awaiting_difference';
    case Ticketed = 'ticketed';
    case RefundDue = 'refund_due';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::CollectingTrip => 'Getting trip details',
            self::AwaitingPassport => 'Passport in',
            self::Confirming => 'Confirming details',
            self::AwaitingQuote => 'Needs your quote',
            self::AwaitingPayment => 'Awaiting payment',
            self::Expired => 'Price hold expired',
            self::Paid => 'Paid · booking',
            self::FareReview => 'Fare review',
            self::AwaitingChoice => 'Waiting on client',
            self::AwaitingDifference => 'Awaiting difference',
            self::Ticketed => 'Ticketed',
            self::RefundDue => 'Refund due',
            self::Refunded => 'Refunded',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Pill tone used across the desk: info | mute | warn | ok | done | bad */
    public function tone(): string
    {
        return match ($this) {
            self::CollectingTrip, self::AwaitingPassport => 'info',
            self::Confirming, self::Expired, self::Refunded, self::Cancelled => 'mute',
            self::AwaitingQuote, self::AwaitingPayment, self::FareReview, self::AwaitingChoice, self::AwaitingDifference => 'warn',
            self::Paid => 'ok',
            self::Ticketed => 'done',
            self::RefundDue => 'bad',
        };
    }

    /** Which pipeline column on the desk this status belongs to. */
    public function column(): ?string
    {
        return match ($this) {
            self::CollectingTrip, self::AwaitingPassport => 'passport',
            self::Confirming, self::AwaitingQuote => 'confirming',
            self::AwaitingPayment, self::Expired => 'payment',
            self::Paid, self::FareReview, self::AwaitingChoice, self::AwaitingDifference => 'paid',
            self::Ticketed => 'ticketed',
            default => null,
        };
    }

    /** Bookings still moving through the flow. */
    public static function open(): array
    {
        return [
            self::CollectingTrip, self::AwaitingPassport, self::Confirming, self::AwaitingQuote, self::AwaitingPayment,
            self::Expired, self::Paid, self::FareReview, self::AwaitingChoice, self::AwaitingDifference,
        ];
    }

    public static function closed(): array
    {
        return [self::RefundDue, self::Refunded, self::Cancelled];
    }

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }
}
