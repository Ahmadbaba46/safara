<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Passport;
use App\Models\User;
use App\Services\PassportService;
use App\Services\PaymentService;
use App\Services\Settings;
use App\Services\TicketingService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
| Run the scheduler every minute in production:
|   * * * * * cd /path/to/safara && php artisan schedule:run >> /dev/null 2>&1
| and keep a queue worker running:  php artisan queue:work
*/

Artisan::command('safara:tick', function (PaymentService $payments, Settings $settings, TicketingService $ticketing, PassportService $passports) {
    // 1. Price holds that ran out.
    $expired = $payments->expireHolds();

    // 2. Bookings held by the overnight pause, once the pause is over.
    $released = 0;
    if (! $settings->nightPauseActive() && $settings->get('auto_issue')) {
        Booking::query()->where('status', BookingStatus::Paid)->get()
            ->filter(fn (Booking $b) => $b->stateGet('auto_pending'))
            ->each(function (Booking $b) use ($ticketing, &$released) {
                $b->stateSet('auto_pending', false)->save();
                $ticketing->checkFareAndProceed($b);
                $released++;
            });
    }

    // 3. Passport data past its keep-until date.
    $wiped = 0;
    Passport::query()->whereNotNull('delete_after')->where('delete_after', '<', now())->each(function (Passport $p) use ($passports, &$wiped) {
        $passports->wipe($p);
        $wiped++;
    });

    $this->info("Expired $expired holds, released $released paused bookings, wiped $wiped passports.");
})->purpose('Expire price holds, release overnight-paused bookings, delete old passport data');

Schedule::command('safara:tick')->everyMinute()->withoutOverlapping();

Artisan::command('safara:operator {email} {--name=Operator} {--password=}', function (string $email) {
    $password = $this->option('password') ?: $this->secret('Password');
    $user = User::query()->updateOrCreate(['email' => $email], ['name' => $this->option('name'), 'password' => $password]);
    $this->info("Desk login ready for {$user->email}.");
})->purpose('Create or update a desk login');
