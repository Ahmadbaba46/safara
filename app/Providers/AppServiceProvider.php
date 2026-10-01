<?php

namespace App\Providers;

use App\Contracts\FlightSearch;
use App\Contracts\PassportReader;
use App\Contracts\PaymentGateway;
use App\Contracts\TripParser;
use App\Contracts\WhatsAppClient;
use App\Enums\BookingStatus;
use App\Integrations\Flights\DuffelFlightSearch;
use App\Integrations\Flights\FakeFlightSearch;
use App\Integrations\Flights\ManualFlightSearch;
use App\Integrations\DeepSeek\DeepSeekClient;
use App\Integrations\Passport\DeepSeekPassportReader;
use App\Integrations\Passport\FakePassportReader;
use App\Integrations\Payments\FakePaymentGateway;
use App\Integrations\Trip\DeepSeekTripParser;
use App\Integrations\Trip\RulesTripParser;
use App\Integrations\WhatsApp\FakeWhatsApp;
use App\Integrations\WhatsApp\MetaWhatsApp;
use App\Models\Booking;
use App\Services\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);

        $this->app->singleton(DeepSeekClient::class, fn () => new DeepSeekClient(config('safara.deepseek')));

        $this->app->singleton(WhatsAppClient::class, fn ($app) => match (config('safara.drivers.whatsapp')) {
            'meta' => new MetaWhatsApp(config('safara.meta')),
            'fake' => new FakeWhatsApp,
            default => throw new InvalidArgumentException('Unknown WhatsApp driver'),
        });

        $this->app->singleton(PassportReader::class, fn ($app) => match (config('safara.drivers.passport_reader')) {
            'deepseek' => new DeepSeekPassportReader($app->make(DeepSeekClient::class)),
            'fake' => new FakePassportReader,
            default => throw new InvalidArgumentException('Unknown passport reader driver'),
        });

        $this->app->singleton(TripParser::class, fn ($app) => match (config('safara.drivers.trip_parser')) {
            'deepseek' => new DeepSeekTripParser($app->make(DeepSeekClient::class)),
            'rules' => new RulesTripParser,
            default => throw new InvalidArgumentException('Unknown trip parser driver'),
        });

        $this->app->singleton(FlightSearch::class, fn ($app) => match (config('safara.drivers.flights')) {
            'duffel' => new DuffelFlightSearch(config('safara.duffel')),
            'fake' => new FakeFlightSearch,
            'manual' => new ManualFlightSearch,
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
        $this->rateLimits();

        Paginator::defaultView('partials.pagination');

        View::composer('layouts.desk', function ($view) {
            $view->with('connections', [
                ['WhatsApp Cloud API', config('safara.drivers.whatsapp') === 'meta'],
                ['Flight search', in_array(config('safara.drivers.flights'), ['duffel'], true)],
                ['Passport reader', config('safara.drivers.passport_reader') === 'deepseek'],
                ['Payments', config('safara.drivers.payments') !== 'fake'],
            ]);
            // Bookings waiting on a person: fare reviews, paid-but-held, and chats handed over.
            $view->with('attention', Booking::query()->open()->where(fn ($q) => $q
                ->whereIn('status', [BookingStatus::FareReview, BookingStatus::Paid, BookingStatus::AwaitingQuote])
                ->orWhere('bot_paused', true))->count());
        });
    }

    /**
     * One named limiter per kind of request. Plain "throttle:10,1" middleware shares a
     * single counter per visitor across every route that uses it, so the app's polling
     * used up the budget of unrelated buttons like "delete my data".
     */
    private function rateLimits(): void
    {
        // App clients are told apart by their device cookie, so people behind one phone-network IP don't share a limit.
        $device = fn (Request $r) => (string) ($r->cookie('safara_app') ?: $r->ip());

        RateLimiter::for('app-poll', fn (Request $r) => Limit::perMinute(120)->by($device($r)));
        RateLimiter::for('app-send', fn (Request $r) => Limit::perMinute(40)->by($device($r)));
        RateLimiter::for('app-document', fn (Request $r) => Limit::perMinute(60)->by($device($r)));
        RateLimiter::for('app-forget', fn (Request $r) => Limit::perMinute(10)->by($device($r)));
        RateLimiter::for('app-start', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
        RateLimiter::for('pay', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));
        RateLimiter::for('pay-requote', fn (Request $r) => Limit::perMinutes(10, 3)->by($r->ip()));
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('webhooks', fn (Request $r) => Limit::perMinute(600)->by($r->ip()));
    }
}
