<?php

namespace App\Integrations\Passport;

use App\Contracts\PassportReader;
use App\Integrations\Data\PassportReading;
use App\Integrations\DeepSeek\DeepSeekClient;
use App\Support\Mrz;

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

        return self::fromModel($data);
    }

    /** Merge the model's reading with the MRZ (public for tests). */
    public static function fromModel(array $data): PassportReading
    {
        $c = $data['confidence'] ?? [];
        $fields = [
            'surname' => self::clean($data['surname'] ?? null),
            'given_names' => self::clean($data['given_names'] ?? null),
            'number' => self::clean($data['passport_number'] ?? null),
            'nationality' => self::clean($data['nationality'] ?? null),
            'issuing_country' => self::clean($data['issuing_country'] ?? null),
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'sex' => in_array($data['sex'] ?? null, ['M', 'F'], true) ? $data['sex'] : null,
            'expiry' => $data['expiry'] ?? null,
        ];
        $confidence = [
            'surname' => (int) ($c['surname'] ?? 0), 'given_names' => (int) ($c['given_names'] ?? 0),
            'number' => (int) ($c['passport_number'] ?? 0), 'nationality' => (int) ($c['nationality'] ?? 0),
            'issuing_country' => (int) ($c['issuing_country'] ?? 0), 'date_of_birth' => (int) ($c['date_of_birth'] ?? 0),
            'sex' => (int) ($c['sex'] ?? 0), 'expiry' => (int) ($c['expiry'] ?? 0),
        ];

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

        return new PassportReading($fields, $confidence, $mrzValid, (bool) ($data['is_passport'] ?? true));
    }

    private static function clean(?string $v): ?string
    {
        $v = $v === null ? null : trim(preg_replace('/\s+/', ' ', strtoupper($v)));

        return $v === '' ? null : $v;
    }
}
