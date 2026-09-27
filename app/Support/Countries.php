<?php

namespace App\Support;

/** Passports use ISO alpha-3 codes; Duffel wants alpha-2. */
final class Countries
{
    public const ALPHA3_TO_ALPHA2 = [
        'NGA' => 'NG', 'GHA' => 'GH', 'NER' => 'NE', 'TCD' => 'TD', 'CMR' => 'CM', 'BEN' => 'BJ', 'TGO' => 'TG',
        'SEN' => 'SN', 'CIV' => 'CI', 'MLI' => 'ML', 'BFA' => 'BF', 'GIN' => 'GN', 'SLE' => 'SL', 'LBR' => 'LR',
        'GMB' => 'GM', 'MRT' => 'MR', 'SDN' => 'SD', 'EGY' => 'EG', 'LBY' => 'LY', 'TUN' => 'TN', 'DZA' => 'DZ',
        'MAR' => 'MA', 'ETH' => 'ET', 'KEN' => 'KE', 'UGA' => 'UG', 'TZA' => 'TZ', 'RWA' => 'RW', 'ZAF' => 'ZA',
        'SAU' => 'SA', 'ARE' => 'AE', 'QAT' => 'QA', 'KWT' => 'KW', 'OMN' => 'OM', 'BHR' => 'BH', 'JOR' => 'JO',
        'TUR' => 'TR', 'GBR' => 'GB', 'IRL' => 'IE', 'USA' => 'US', 'CAN' => 'CA', 'FRA' => 'FR', 'DEU' => 'DE',
        'ITA' => 'IT', 'ESP' => 'ES', 'NLD' => 'NL', 'BEL' => 'BE', 'CHE' => 'CH', 'SWE' => 'SE', 'NOR' => 'NO',
        'CHN' => 'CN', 'IND' => 'IN', 'PAK' => 'PK', 'BGD' => 'BD', 'MYS' => 'MY', 'IDN' => 'ID', 'SGP' => 'SG',
        'THA' => 'TH', 'PHL' => 'PH', 'LBN' => 'LB', 'SYR' => 'SY', 'IRN' => 'IR', 'IRQ' => 'IQ', 'BRA' => 'BR',
        'AUS' => 'AU', 'JPN' => 'JP', 'KOR' => 'KR', 'RUS' => 'RU', 'UKR' => 'UA', 'POL' => 'PL', 'PRT' => 'PT',
    ];

    public const NAMES = [
        'NGA' => 'Nigerian', 'GHA' => 'Ghanaian', 'NER' => 'Nigerien', 'CMR' => 'Cameroonian', 'TCD' => 'Chadian',
        'BEN' => 'Beninese', 'SEN' => 'Senegalese', 'SDN' => 'Sudanese', 'EGY' => 'Egyptian', 'SAU' => 'Saudi',
        'GBR' => 'British', 'USA' => 'American',
    ];

    public static function alpha2(?string $alpha3): ?string
    {
        if (! $alpha3) {
            return null;
        }
        $alpha3 = strtoupper($alpha3);

        return self::ALPHA3_TO_ALPHA2[$alpha3] ?? (strlen($alpha3) === 2 ? $alpha3 : null);
    }

    public static function demonym(?string $alpha3): string
    {
        return $alpha3 ? (self::NAMES[strtoupper($alpha3)] ?? strtoupper($alpha3)) : '—';
    }
}
