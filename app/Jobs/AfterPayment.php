<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\TicketingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AfterPayment implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $paymentId) {}

    public function handle(TicketingService $ticketing): void
    {
        $payment = Payment::query()->find($this->paymentId);
        if ($payment) {
            $ticketing->afterPayment($payment);
        }
    }
}
