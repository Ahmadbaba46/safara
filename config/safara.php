<?php

/*
|--------------------------------------------------------------------------
| Safara — WhatsApp flight desk
|--------------------------------------------------------------------------
|
| Every outside service sits behind a driver. "fake" drivers let the whole
| flow run locally (use the Simulator at /dev/simulator) without WhatsApp,
| Duffel, DeepSeek or a payment provider.
|
| Business rules operators can change live (markup, price hold, ticketing
| rules...) are NOT here — they live in the settings table and are edited
| from the desk's Settings screen. The values below are only their defaults.
|
*/

return [

    'brand' => env('SAFARA_BRAND', 'Safara Travel'),

    'currency' => 'NGN',

    'timezone' => env('SAFARA_TIMEZONE', 'Africa/Lagos'),

    'drivers' => [
        // meta | fake
        'whatsapp' => env('SAFARA_WHATSAPP_DRIVER', 'fake'),
        // deepseek | fake
        'passport_reader' => env('SAFARA_PASSPORT_DRIVER', 'fake'),
        // deepseek | rules
        'trip_parser' => env('SAFARA_TRIP_PARSER', 'rules'),
        // duffel | manual (no API: a person quotes and tickets on the desk) | fake
        'flights' => env('SAFARA_FLIGHTS_DRIVER', 'fake'),
        // fake, or the fully-qualified class name of your own
        // App\Contracts\PaymentGateway implementation.
        'payments' => env('SAFARA_PAYMENTS_DRIVER', 'fake'),
    ],

    // The Safara app: the standalone chat clients use instead of WhatsApp.
    'app' => [
        'enabled' => env('SAFARA_APP', true),
    ],

    // Demo mode lets a deployed copy run on the fake drivers: the test payment
    // page works in production, so nobody is charged and no ticket is issued.
    'demo' => env('SAFARA_DEMO', false),

    // The desk's WhatsApp simulator. Never enable in production.
    'simulator' => env('SAFARA_SIMULATOR', env('APP_ENV') !== 'production'),

    'meta' => [
        'graph_url' => env('META_GRAPH_URL', 'https://graph.facebook.com'),
        'graph_version' => env('META_GRAPH_VERSION', 'v21.0'),
        'token' => env('META_WHATSAPP_TOKEN'),
        'phone_number_id' => env('META_WHATSAPP_PHONE_NUMBER_ID'),
        'verify_token' => env('META_WHATSAPP_VERIFY_TOKEN'),
        'app_secret' => env('META_APP_SECRET'),
        'business_number' => env('META_WHATSAPP_BUSINESS_NUMBER'),
    ],

    // DeepSeek (https://api-docs.deepseek.com): reads passport photos and understands trip
    // messages. "deepseek-flash" is the current V4.1 Flash model and accepts images.
    // Any OpenAI-compatible endpoint also works: change the URL, key and model.
    'deepseek' => [
        'key' => env('DEEPSEEK_API_KEY'),
        'url' => env('DEEPSEEK_URL', 'https://api.deepseek.com'),
        'model' => env('DEEPSEEK_MODEL', 'deepseek-flash'),
    ],

    'duffel' => [
        'token' => env('DUFFEL_ACCESS_TOKEN'),
        'url' => env('DUFFEL_URL', 'https://api.duffel.com'),
        'version' => env('DUFFEL_VERSION', 'v2'),
        // Duffel needs a contact email on every order.
        'contact_email' => env('DUFFEL_CONTACT_EMAIL'),
    ],

    // Where alerts for fare reviews and hand-overs go (international format, no +).
    'operator_phone' => env('SAFARA_OPERATOR_PHONE'),

    // How many unreadable passport photos before a person takes over.
    'max_photo_attempts' => 3,

    // Passports must be valid this many months after the last travel date.
    'passport_validity_months' => 6,

    'defaults' => [
        'markup_percent' => 6,
        'min_margin' => 10000,
        'round_to' => 1000,
        'hold_minutes' => 120,
        'payment_methods' => ['card', 'transfer', 'ussd'],
        'auto_issue' => true,
        'absorb' => true,
        'absorb_limit' => 5000,
        'alert_operator' => true,
        'night_pause' => false,
        'night_start' => '22:00',
        'night_end' => '06:00',
        'retention' => 'expiry', // expiry | after_travel | after_ticket
        'refund_time' => 'a few working days',
        // Rates used when Duffel prices an offer in another currency.
        'fx_rates' => ['NGN' => 1, 'USD' => 1550, 'GBP' => 1980, 'EUR' => 1690],
        'cabin_class' => 'economy',
    ],
];
