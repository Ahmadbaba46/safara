<?php

namespace App\Contracts;

use App\Integrations\Data\PassportReading;

interface PassportReader
{
    public function read(string $imageBytes, string $mime): PassportReading;
}
