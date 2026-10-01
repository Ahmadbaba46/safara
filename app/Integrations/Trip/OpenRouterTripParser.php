<?php

namespace App\Integrations\Trip;

use App\Contracts\TripParser;
use App\Integrations\OpenRouter\OpenRouterClient;
use App\Support\Iata;
use App\Support\TripRequest;
use App\Support\TripRules;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks a model on OpenRouter to pull the trip out of a free-form message (English, Hausa or
 * a mix), and falls back to the rules parser if the call fails.
 */
class OpenRouterTripParser implements TripParser
{
    /** @param string[] $models text models, preferred first */
    public function __construct(private OpenRouterClient $client, private array $models) {}

    public function parse(string $text, \DateTimeImmutable $today): TripRequest
    {
        $rules = (new TripRules($today))->parse($text);

        // Nothing that looks like a trip? Don't spend a call on "hello".
        if (mb_strlen(trim($text)) < 4) {
            return $rules;
        }

        try {
            $data = $this->client->json([['type' => 'text', 'text' => $text]], $this->system($today), $this->models, 300);
        } catch (Throwable $e) {
            Log::warning('Trip parser fell back to rules: '.$e->getMessage());

            return $rules;
        }

        $req = new TripRequest(
            origin: self::code($data['origin'] ?? null) ?? $rules->origin,
            destination: self::code($data['destination'] ?? null) ?? $rules->destination,
            departOn: self::date($data['depart_on'] ?? null, $today) ?? $rules->departOn,
            returnOn: self::date($data['return_on'] ?? null, $today) ?? $rules->returnOn,
            travellers: isset($data['travellers']) && (int) $data['travellers'] > 0 && (int) $data['travellers'] < 10
                ? (int) $data['travellers'] : $rules->travellers,
            language: in_array($data['language'] ?? null, ['en', 'ha'], true) ? $data['language'] : $rules->language,
        );

        return $req;
    }

    private function system(\DateTimeImmutable $today): string
    {
        return 'You extract flight requests from WhatsApp messages sent to a Nigerian travel agency. '
            .'Messages may be English, Hausa or both. Today is '.$today->format('l j F Y').'. '
            .'Return ONLY JSON: {"origin": IATA airport code or null, "destination": IATA code or null, '
            .'"depart_on": "YYYY-MM-DD" or null, "return_on": "YYYY-MM-DD" or null, '
            .'"travellers": number or null, "language": "en" or "ha"}. '
            .'Makkah means JED, Madinah means MED. Dates without a year are the next time that date occurs. '
            .'Leave anything the message does not say as null; never guess an origin.';
    }

    private static function code(mixed $v): ?string
    {
        if (! is_string($v) || ! preg_match('/^[A-Za-z]{3}$/', $v)) {
            return null;
        }

        return strtoupper($v);
    }

    private static function date(mixed $v, \DateTimeImmutable $today): ?string
    {
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }

        return $v >= $today->format('Y-m-d') ? $v : null;
    }
}
