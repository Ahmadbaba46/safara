<?php

namespace App\Services;

use App\Contracts\PassportReader;
use App\Contracts\WhatsAppClient;
use App\Integrations\Data\PassportReading;
use App\Models\Booking;
use App\Models\Passport;
use App\Support\Countries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PassportService
{
    public function __construct(
        private WhatsAppClient $wa,
        private PassportReader $reader,
        private Settings $settings,
    ) {}

    /**
     * Download a WhatsApp photo, keep an encrypted copy and read it.
     *
     * @return array{0: PassportReading, 1: string} reading and stored path
     */
    public function readFromWhatsApp(string $mediaId, ?string $mime): array
    {
        $media = str_starts_with($mediaId, 'app_')
            ? ['bytes' => Storage::disk('local')->get('app-uploads/'.basename($mediaId)), 'mime' => 'image/jpeg']
            : $this->wa->downloadMedia($mediaId);
        $path = 'passports/'.now()->format('Y/m').'/'.Str::uuid().'.enc';
        Storage::disk('local')->put($path, Crypt::encryptString(base64_encode($media['bytes'])));

        return [$this->reader->read($media['bytes'], $mime ?: $media['mime']), $path];
    }

    public function photo(Passport $passport): ?string
    {
        if (! $passport->image_path || ! Storage::disk('local')->exists($passport->image_path)) {
            return null;
        }

        return base64_decode(Crypt::decryptString(Storage::disk('local')->get($passport->image_path)));
    }

    public function createFromReading(Booking $booking, PassportReading $r, string $imagePath): Passport
    {
        $position = $booking->passports()->count() + 1;

        $passport = $booking->client->passports()->create([
            'surname' => $r->fields['surname'],
            'given_names' => $r->fields['given_names'],
            'number' => $r->fields['number'],
            'date_of_birth' => $r->fields['date_of_birth'],
            'nationality' => $r->fields['nationality'],
            'issuing_country' => $r->fields['issuing_country'] ?? $r->fields['nationality'],
            'sex' => $r->fields['sex'],
            'expiry' => $r->fields['expiry'],
            'confidence' => $r->confidence,
            'mrz_valid' => $r->mrzValid,
            'image_path' => $imagePath,
        ]);
        $booking->passports()->attach($passport->id, ['position' => $position]);

        return $passport;
    }

    public function attachSaved(Booking $booking, Passport $passport): void
    {
        if (! $booking->passports()->whereKey($passport->id)->exists()) {
            $booking->passports()->attach($passport->id, ['position' => $booking->passports()->count() + 1]);
        }
    }

    /** Does the passport stay valid long enough after the trip? */
    public function expiresTooSoon(Passport $passport, Booking $booking): bool
    {
        $last = $booking->return_on ?? $booking->depart_on;
        if (! $passport->expiry || ! $last) {
            return false;
        }

        return $passport->expiry->lt($last->copy()->addMonths(config('safara.passport_validity_months')));
    }

    /** "Name: AISHA BELLO\nPassport: A09•••421..." for the confirm message. */
    public function details(Booking $booking, string $lang = 'en'): string
    {
        $L = $lang === 'ha'
            ? ['name' => 'Suna', 'passport' => 'Fasfo', 'born' => 'Haihuwa', 'expires' => 'Ƙarewa', 'trip' => 'Tafiya', 'traveller' => 'Matafiyi', 'return' => 'dawowa']
            : ['name' => 'Name', 'passport' => 'Passport', 'born' => 'Born', 'expires' => 'Expires', 'trip' => 'Trip', 'traveller' => 'Traveller', 'return' => 'return'];

        $blocks = [];
        $passports = $booking->passports()->get();
        foreach ($passports as $i => $p) {
            $lines = [];
            if ($passports->count() > 1) {
                $lines[] = $L['traveller'].' '.($i + 1);
            }
            $lines[] = $L['name'].': '.$p->fullName();
            $lines[] = $L['passport'].': '.$p->maskedNumber();
            $lines[] = $L['born'].': '.($p->dob()?->format('d M Y') ?? '—');
            $lines[] = $L['expires'].': '.($p->expiry?->format('d M Y') ?? '—');
            $blocks[] = implode("\n", $lines);
        }
        $trip = $L['trip'].': '.$booking->routeCodes().' · '.$booking->dateLabel();
        if ($booking->return_on) {
            $trip .= ' · '.$L['return'].' '.$booking->return_on->format('D j M');
        }
        $blocks[] = $trip;

        return implode("\n\n", $blocks);
    }

    /**
     * Apply a client's correction. Returns [label, display value] or null if we
     * couldn't make sense of it.
     *
     * @return array{0: string, 1: string}|null
     */
    public function applyFix(Passport $p, string $field, string $text): ?array
    {
        $text = trim($text);
        switch ($field) {
            case 'name':
                $clean = strtoupper(preg_replace('/[^\p{L}\s,\'-]/u', '', $text));
                $parts = str_contains($clean, ',') ? array_map('trim', explode(',', $clean, 2)) : preg_split('/\s+/', trim($clean), 2);
                if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
                    return null;
                }
                $p->update(['surname' => $parts[0], 'given_names' => preg_replace('/\s+/', ' ', $parts[1]), 'confidence' => array_merge($p->confidence ?? [], ['surname' => 100, 'given_names' => 100])]);

                return ['Name', $p->fullName()];

            case 'dob':
                $date = self::parseDate($text);
                if (! $date || $date->isFuture() || $date->year < 1900) {
                    return null;
                }
                $p->update(['date_of_birth' => $date->toDateString(), 'confidence' => array_merge($p->confidence ?? [], ['date_of_birth' => 100])]);

                return ['Date of birth', $date->format('d M Y')];

            case 'number':
                $num = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $text));
                if (strlen($num) < 6 || strlen($num) > 12) {
                    return null;
                }
                $p->update(['number' => $num, 'confidence' => array_merge($p->confidence ?? [], ['number' => 100])]);

                return ['Passport number', $p->maskedNumber()];

            case 'expiry':
                $date = self::parseDate($text);
                if (! $date || $date->isPast()) {
                    return null;
                }
                $p->update(['expiry' => $date->toDateString(), 'confidence' => array_merge($p->confidence ?? [], ['expiry' => 100])]);

                return ['Expiry date', $date->format('d M Y')];
        }

        return null;
    }

    /** "17 11 1988", "17/11/1988", "17-11-88", "17 Nov 1988", "1988-11-17" (day first). */
    public static function parseDate(string $text): ?Carbon
    {
        $t = strtolower(trim($text));
        try {
            if (preg_match('/^(\d{4})[\/.\- ](\d{1,2})[\/.\- ](\d{1,2})$/', $t, $m)) {
                return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? Carbon::create((int) $m[1], (int) $m[2], (int) $m[3]) : null;
            }
            if (preg_match('/^(\d{1,2})[\/.\- ]+(\d{1,2})[\/.\- ]+(\d{2,4})$/', $t, $m)) {
                $y = (int) $m[3];
                if ($y < 100) {
                    $y += $y > (int) date('y') + 20 ? 1900 : 2000;
                }

                return checkdate((int) $m[2], (int) $m[1], $y) ? Carbon::create($y, (int) $m[2], (int) $m[1]) : null;
            }
            if (preg_match('/^(\d{1,2})\s*([a-z]{3,9})\.?\s*,?\s*(\d{4})$/', $t, $m)) {
                return Carbon::parse($m[1].' '.$m[2].' '.$m[3]);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /** Duffel passenger payload for a passport. */
    public function toPassenger(Passport $p): array
    {
        return [
            'given_name' => mb_convert_case(strtolower((string) $p->given_names), MB_CASE_TITLE),
            'family_name' => mb_convert_case(strtolower((string) $p->surname), MB_CASE_TITLE),
            'born_on' => $p->date_of_birth,
            'gender' => $p->sex === 'M' ? 'm' : 'f',
            'title' => $p->sex === 'M' ? 'mr' : 'ms',
            'passport_number' => $p->number,
            'passport_country' => Countries::alpha2($p->issuing_country ?: $p->nationality),
            'passport_expires_on' => $p->expiry?->toDateString(),
        ];
    }

    /** Apply the retention setting once a booking is ticketed. */
    public function scheduleDeletion(Booking $booking): void
    {
        $retention = $this->settings->get('retention', 'expiry');
        $last = $booking->return_on ?? $booking->depart_on ?? now();

        foreach ($booking->passports as $p) {
            $p->delete_after = match (true) {
                $retention === 'after_ticket' => now()->addDay(),                         // kept a day for any follow-up
                $retention === 'expiry' && $p->consent_at !== null => $p->expiry?->copy()->endOfDay(),
                default => $last->copy()->addDays(30),                                    // after_travel, or no consent yet
            };
            $p->save();
        }
    }

    /** Delete the passport's personal data and photo. */
    public function wipe(Passport $p): void
    {
        if ($p->image_path) {
            Storage::disk('local')->delete($p->image_path);
        }
        $p->bookings()->detach();
        $p->delete();
    }
}
