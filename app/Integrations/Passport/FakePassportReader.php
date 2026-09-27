<?php

namespace App\Integrations\Passport;

use App\Contracts\PassportReader;
use App\Integrations\Data\PassportReading;

/**
 * For local work and tests. Any image reads as a valid Nigerian passport,
 * except bytes containing "BLURRY", which read as a photo where the passport
 * number can't be made out. The Simulator's "Send blurry photo" uses that.
 * Bytes containing "NAME:SURNAME GIVEN" set the name, so each traveller in a
 * group can have their own passport.
 */
class FakePassportReader implements PassportReader
{
    public function read(string $imageBytes, string $mime): PassportReading
    {
        $surname = 'BELLO';
        $given = 'AISHA';
        if (preg_match('/NAME:([A-Z]+) ([A-Z ]+)/', $imageBytes, $m)) {
            [$surname, $given] = [$m[1], trim($m[2])];
        }

        $fields = [
            'surname' => $surname,
            'given_names' => $given,
            'number' => 'A09'.substr((string) abs(crc32($surname.$given)), 0, 6),
            'nationality' => 'NGA',
            'issuing_country' => 'NGA',
            'date_of_birth' => '1991-06-04',
            'sex' => 'F',
            'expiry' => '2031-03-14',
        ];
        $confidence = array_fill_keys(array_keys($fields), 99);
        $confidence['date_of_birth'] = 86;

        if (str_contains($imageBytes, 'BLURRY')) {
            $fields['number'] = null;
            $confidence['number'] = 20;

            return new PassportReading($fields, $confidence, false);
        }

        return new PassportReading($fields, $confidence, true);
    }
}
