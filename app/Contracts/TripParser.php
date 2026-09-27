<?php

namespace App\Contracts;

use App\Support\TripRequest;

interface TripParser
{
    public function parse(string $text, \DateTimeImmutable $today): TripRequest;
}
