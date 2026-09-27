<?php

namespace App\Integrations\Data;

final class PassportReading
{
    public const REQUIRED = ['surname', 'given_names', 'number', 'nationality', 'date_of_birth', 'sex', 'expiry'];

    public const LABELS = [
        'surname' => 'surname', 'given_names' => 'given names', 'number' => 'passport number',
        'nationality' => 'nationality', 'date_of_birth' => 'date of birth', 'sex' => 'sex', 'expiry' => 'expiry date',
    ];

    /**
     * @param  array<string, ?string>  $fields  surname, given_names, number, nationality,
     *                                          issuing_country, date_of_birth (Y-m-d), sex (M|F), expiry (Y-m-d)
     * @param  array<string, int>  $confidence  0–100 per field
     */
    public function __construct(
        public array $fields,
        public array $confidence = [],
        public bool $mrzValid = false,
        public bool $isPassport = true,
    ) {}

    /** Fields we couldn't read well enough to use. */
    public function unreadable(int $minConfidence = 60): array
    {
        $missing = [];
        foreach (self::REQUIRED as $f) {
            $value = $this->fields[$f] ?? null;
            if ($value === null || $value === '' || ($this->confidence[$f] ?? 100) < $minConfidence) {
                $missing[] = $f;
            }
        }

        return $missing;
    }

    public function usable(): bool
    {
        return $this->isPassport && $this->unreadable() === [];
    }

    /** "passport number" / "passport number and expiry date" */
    public function unreadableLabel(): string
    {
        $labels = array_map(fn ($f) => self::LABELS[$f], $this->unreadable());
        if (! $this->isPassport || count($labels) > 3) {
            return 'passport details';
        }
        if (count($labels) <= 1) {
            return $labels[0] ?? 'passport details';
        }
        $last = array_pop($labels);

        return implode(', ', $labels).' and '.$last;
    }
}
