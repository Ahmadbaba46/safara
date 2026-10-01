<?php

namespace App\Integrations\Passport;

use App\Contracts\PassportReader;
use App\Integrations\Data\PassportReading;
use App\Integrations\DeepSeek\DeepSeekClient;
use App\Support\Mrz;
use Illuminate\Support\Facades\Log;

/**
 * Reads the passport photo page with DeepSeek's vision model, then trusts the MRZ:
 * when its check digits pass, MRZ values win over what was read from the
 * printed text and get full confidence.
 */
class DeepSeekPassportReader implements PassportReader
{
    private const SYSTEM = <<<'TXT'
You read passport photo pages for a travel agency so a flight can be booked in the traveller's exact passport name.
Return ONLY a JSON object, no prose, with these keys:
{
  "is_passport": true|false,
  "mrz_line1": "44 characters or null",
  "mrz_line2": "44 characters or null",
  "surname": "as printed, upper case, or null",
  "given_names": "as printed, upper case, or null",
  "passport_number": "or null",
  "nationality": "ISO 3166-1 alpha-3, or null",
  "issuing_country": "ISO 3166-1 alpha-3, or null",
  "date_of_birth": "YYYY-MM-DD or null",
  "sex": "M" | "F" | null,
  "expiry": "YYYY-MM-DD or null",
  "confidence": { "<each field above>": 0-100 }
}
Copy the MRZ exactly, using "<" for filler characters. If a field is blurred, cut off, covered by glare or you are guessing, set it to null or give low confidence. Never invent values.
TXT;

    public function __construct(private DeepSeekClient $client) {}

    public function read(string $imageBytes, string $mime): PassportReading
    {
        $mime = in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) ? $mime : 'image/jpeg';

        $data = $this->client->json([
            ['type' => 'image', 'mime' => $mime, 'data' => base64_encode($imageBytes)],
            ['type' => 'text', 'text' => 'Read this passport photo page.'],
        ], self::SYSTEM, 800);

        $reading = self::fromModel($data);
        if (! $reading->usable()) {
            // No passport data in the log: only which fields were rejected and how sure the model was.
            Log::warning('DeepSeek passport reading unusable', [
                'is_passport' => $reading->isPassport,
                'unreadable' => $reading->unreadable(),
                'confidence' => $reading->confidence,
                'mrz_valid' => $reading->mrzValid,
                'keys_returned' => array_keys($data),
            ]);
        }

        return $reading;
    }

    /** Merge the model's reading with the MRZ (public for tests). */
    public static function fromModel(array $data): PassportReading
    {
        $c = is_array($data['confidence'] ?? null) ? $data['confidence'] : [];
        $fields = [
            'surname' => self::clean($data['surname'] ?? null),
            'given_names' => self::clean($data['given_names'] ?? null),
            'number' => self::clean($data['passport_number'] ?? null),
            'nationality' => self::clean($data['nationality'] ?? null),
            'issuing_country' => self::clean($data['issuing_country'] ?? null),
            'date_of_birth' => self::date($data['date_of_birth'] ?? null),
            'sex' => self::sex($data['sex'] ?? null),
            'expiry' => self::date($data['expiry'] ?? null),
        ];
        // The model's own names for the fields differ from ours in two places.
        $source = ['number' => 'passport_number'];
        $confidence = [];
        foreach ($fields as $field => $value) {
            $confidence[$field] = self::score($c[$source[$field] ?? $field] ?? null, $value !== null);
        }

        $mrzValid = false;
        if (! empty($data['mrz_line1']) && ! empty($data['mrz_line2'])) {
            $mrz = Mrz::parse($data['mrz_line1'], $data['mrz_line2']);
            if ($mrz) {
                $checks = $mrz['checks'];
                $mrzValid = $mrz['valid'];
                $map = ['number' => 'number', 'date_of_birth' => 'dob', 'expiry' => 'expiry'];
                foreach ($mrz['fields'] as $field => $value) {
                    if ($value === null) {
                        continue;
                    }
                    $check = $map[$field] ?? null;
                    // A field protected by its own check digit is reliable when that digit passes.
                    $trusted = $check ? $checks[$check] : $mrzValid;
                    if ($trusted) {
                        $fields[$field] = $value;
                        $confidence[$field] = 99;
                    } elseif ($fields[$field] === null) {
                        $fields[$field] = $value;
                        $confidence[$field] = min($confidence[$field] ?: 50, 50);
                    }
                }
            }
        }

        return new PassportReading($fields, $confidence, $mrzValid, self::bool($data['is_passport'] ?? true));
    }

    private static function clean(mixed $v): ?string
    {
        if (! is_scalar($v)) {
            return null;
        }
        $v = trim(preg_replace('/\s+/', ' ', strtoupper((string) $v)));

        return in_array($v, ['', 'NULL', 'NONE', 'N/A', 'NA', 'UNKNOWN', '-', 'UNREADABLE'], true) ? null : $v;
    }

    private static function date(mixed $v): ?string
    {
        $v = self::clean($v);
        if ($v === null) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
            return $v;
        }
        $t = strtotime($v);

        return $t ? date('Y-m-d', $t) : null;
    }

    private static function sex(mixed $v): ?string
    {
        $v = self::clean($v);

        return in_array($v === null ? '' : $v[0], ['M', 'F'], true) ? $v[0] : null;
    }

    private static function bool(mixed $v): bool
    {
        return is_string($v) ? ! in_array(strtolower(trim($v)), ['false', 'no', '0'], true) : (bool) $v;
    }

    /**
     * 0–100 from whatever the model gave: 0–100, 0–1, a numeric string, or nothing.
     * A value with no score is trusted a little (75): the check digits and the
     * client's own confirmation still catch mistakes.
     */
    private static function score(mixed $raw, bool $hasValue): int
    {
        if (! $hasValue) {
            return 0;
        }
        if (is_string($raw)) {
            $raw = rtrim(trim($raw), '%');
        }
        if (! is_numeric($raw)) {
            return 75;
        }
        $n = (float) $raw;
        if ($n > 0 && $n <= 1) {
            $n *= 100;
        }

        return (int) max(0, min(100, round($n)));
    }
}
