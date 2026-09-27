<?php

namespace App\Providers;

use App\Contracts\FlightSearch;
use App\Contracts\PassportReader;
use App\Contracts\PaymentGateway;
use App\Contracts\TripParser;
use App\Contracts\WhatsAppClient;
use App\Enums\BookingStatus;
use App\Integrations\Anthropic\AnthropicClient;
use App\Integrations\Flights\DuffelFlightSearch;
use App\Integrations\Flights\FakeFlightSearch;
use App\Integrations\Passport\AnthropicPassportReader;
use App\Integrations\Passport\FakePassportReader;
use App\Integrations\Payments\FakePaymentGateway;
use App\Integrations\Trip\AnthropicTripParser;
use App\Integrations\Trip\RulesTripParser;
use App\Integrations\WhatsApp\FakeWhatsApp;
use App\Integrations\WhatsApp\MetaWhatsApp;
use App\Models\Booking;
use App\Services\Settings;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);

        $this->app->singleton(AnthropicClient::class, fn () => new AnthropicClient(config('safara.anthropic')));

        $this->app->singleton(WhatsAppClient::class, fn ($app) => match (config('safara.drivers.whatsapp')) {
            'meta' => new MetaWhatsApp(config('safara.meta')),
            'fake' => new FakeWhatsApp,
            default => throw new InvalidArgumentException('Unknown WhatsApp driver'),
        });

        $this->app->singleton(PassportReader::class, fn ($app) => match (config('safara.drivers.passport_reader')) {
            'anthropic' => new AnthropicPassportReader($app->make(AnthropicClient::class)),
            'fake' => new FakePassportReader,
            default => throw new InvalidArgumentException('Unknown passport reader driver'),
        });

        $this->app->singleton(TripParser::class, fn ($app) => match (config('safara.drivers.trip_parser')) {
            'anthropic' => new AnthropicTripParser($app->make(AnthropicClient::class)),
            'rules' => new RulesTripParser,
            default => throw new InvalidArgumentException('Unknown trip parser driver'),
        });

        $this->app->singleton(FlightSearch::class, fn ($app) => match (config('safara.drivers.flights')) {
            'duffel' => new DuffelFlightSearch(config('safara.duffel')),
            'fake' => new FakeFlightSearch,
            default => throw new InvalidArgumentException('Unknown flights driver'),
        });

        $this->app->singleton(PaymentGateway::class, function ($app) {
            $driver = config('safara.drivers.payments');
            if ($driver === 'fake') {
                return new FakePaymentGateway;
            }
            if (class_exists($driver) && is_subclass_of($driver, PaymentGateway::class)) {
                return $app->make($driver);
            }
            throw new InvalidArgumentException("Payment driver [$driver] must be 'fake' or a class implementing ".PaymentGateway::class);
        });
    }

    public function boot(): void
    {
        Paginator::defaultView('partials.pagination');

        View::composer('layouts.desk', function ($view) {
            $view->with('connections', [
                ['WhatsApp Cloud API', config('safara.drivers.whatsapp') === 'meta'],
                ['Duffel', config('safara.drivers.flights') === 'duffel'],
                ['Passport reader', config('safara.drivers.passport_reader') === 'anthropic'],
                ['Payments', config('safara.drivers.payments') !== 'fake'],
            ]);
            // Bookings waiting on a person: fare reviews, paid-but-held, and chats handed over.
            $view->with('attention', Booking::query()->open()->where(fn ($q) => $q
                ->whereIn('status', [BookingStatus::FareReview, BookingStatus::Paid])
                ->orWhere('bot_paused', true))->count());
        });
    }
}
