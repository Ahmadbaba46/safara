<?php

namespace App\Integrations\Trip;

use App\Contracts\TripParser;
use App\Support\TripRequest;
use App\Support\TripRules;

class RulesTripParser implements TripParser
{
    public function parse(string $text, \DateTimeImmutable $today): TripRequest
    {
        return (new TripRules($today))->parse($text);
    }
}
