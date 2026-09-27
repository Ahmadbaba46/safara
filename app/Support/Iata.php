<?php

namespace App\Support;

/**
 * City names ↔ airport codes for the routes Safara's clients ask for most.
 * Aliases include common Hausa spellings. Extend freely.
 */
final class Iata
{
    /** code => city name */
    public const CITIES = [
        // Nigeria
        'ABV' => 'Abuja', 'LOS' => 'Lagos', 'KAN' => 'Kano', 'KAD' => 'Kaduna', 'PHC' => 'Port Harcourt',
        'ENU' => 'Enugu', 'SKO' => 'Sokoto', 'MIU' => 'Maiduguri', 'YOL' => 'Yola', 'ILR' => 'Ilorin',
        'QOW' => 'Owerri', 'BNI' => 'Benin City', 'CBQ' => 'Calabar', 'JOS' => 'Jos', 'DKA' => 'Katsina',
        'GMO' => 'Gombe', 'BCU' => 'Bauchi', 'ABB' => 'Asaba', 'QUO' => 'Uyo', 'AKR' => 'Akure', 'IBA' => 'Ibadan',
        'MXJ' => 'Minna',
        // Hajj, Umrah and the Gulf
        'JED' => 'Jeddah', 'MED' => 'Medina', 'RUH' => 'Riyadh', 'DMM' => 'Dammam', 'DXB' => 'Dubai',
        'AUH' => 'Abu Dhabi', 'DOH' => 'Doha', 'KWI' => 'Kuwait', 'MCT' => 'Muscat', 'BAH' => 'Bahrain',
        // Africa
        'CAI' => 'Cairo', 'ACC' => 'Accra', 'NBO' => 'Nairobi', 'ADD' => 'Addis Ababa', 'JNB' => 'Johannesburg',
        'CPT' => 'Cape Town', 'CMN' => 'Casablanca', 'KGL' => 'Kigali', 'DSS' => 'Dakar', 'ABJ' => 'Abidjan',
        'NIM' => 'Niamey', 'NDJ' => "N'Djamena", 'DLA' => 'Douala', 'LFW' => 'Lomé', 'COO' => 'Cotonou',
        'KRT' => 'Khartoum', 'DAR' => 'Dar es Salaam', 'TUN' => 'Tunis', 'ALG' => 'Algiers',
        // Europe, Asia, Americas
        'IST' => 'Istanbul', 'LHR' => 'London', 'MAN' => 'Manchester', 'CDG' => 'Paris', 'FRA' => 'Frankfurt',
        'AMS' => 'Amsterdam', 'MAD' => 'Madrid', 'FCO' => 'Rome', 'JFK' => 'New York', 'IAD' => 'Washington',
        'ATL' => 'Atlanta', 'IAH' => 'Houston', 'YYZ' => 'Toronto', 'CAN' => 'Guangzhou', 'PEK' => 'Beijing',
        'BOM' => 'Mumbai', 'DEL' => 'Delhi', 'KUL' => 'Kuala Lumpur', 'BKK' => 'Bangkok', 'SIN' => 'Singapore',
    ];

    /** alias (lower case) => code */
    public const ALIASES = [
        'makka' => 'JED', 'makkah' => 'JED', 'mecca' => 'JED', 'maka' => 'JED', 'jidda' => 'JED', 'jiddah' => 'JED',
        'madina' => 'MED', 'madinah' => 'MED', 'medinah' => 'MED',
        'legas' => 'LOS', 'ikeja' => 'LOS', 'phc' => 'PHC', 'portharcourt' => 'PHC', 'port-harcourt' => 'PHC',
        'benin' => 'BNI', 'kastina' => 'DKA', 'dubae' => 'DXB', 'misra' => 'CAI', 'masar' => 'CAI',
        'london heathrow' => 'LHR', 'heathrow' => 'LHR', 'new york city' => 'JFK', 'nyc' => 'JFK',
        'addis' => 'ADD', 'joburg' => 'JNB', 'kl' => 'KUL', 'lome' => 'LFW',
    ];

    public static function city(string $code): string
    {
        return self::CITIES[strtoupper($code)] ?? strtoupper($code);
    }

    public static function isKnown(string $code): bool
    {
        return isset(self::CITIES[strtoupper($code)]);
    }

    /**
     * Every place mentioned in the text, in the order it appears.
     *
     * @return array<int, array{code: string, pos: int}>
     */
    public static function findAll(string $text): array
    {
        $hay = ' '.mb_strtolower($text).' ';
        $found = [];

        $names = [];
        foreach (self::CITIES as $code => $city) {
            $names[mb_strtolower($city)] = $code;
        }
        foreach (self::ALIASES as $alias => $code) {
            $names[$alias] = $code;
        }
        // Longest names first so "port harcourt" wins over "port".
        uksort($names, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        $taken = [];
        foreach ($names as $name => $code) {
            $pattern = '/(?<![\p{L}])'.preg_quote($name, '/').'(?![\p{L}])/u';
            if (preg_match_all($pattern, $hay, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$match, $pos]) {
                    $range = [$pos, $pos + strlen($match)];
                    foreach ($taken as [$s, $e]) {
                        if ($range[0] < $e && $range[1] > $s) {
                            continue 2;
                        }
                    }
                    $taken[] = $range;
                    $found[] = ['code' => $code, 'pos' => $pos];
                }
            }
        }

        // Bare three-letter codes typed in capitals: "KAN to JED".
        if (preg_match_all('/\b([A-Z]{3})\b/', ' '.$text.' ', $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$code, $pos]) {
                if (self::isKnown($code)) {
                    foreach ($found as $f) {
                        if ($f['code'] === $code) {
                            continue 2;
                        }
                    }
                    $found[] = ['code' => $code, 'pos' => $pos];
                }
            }
        }

        usort($found, fn ($a, $b) => $a['pos'] <=> $b['pos']);

        return $found;
    }
}
