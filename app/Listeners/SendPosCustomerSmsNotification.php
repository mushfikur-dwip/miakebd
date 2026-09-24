<?php

namespace App\Listeners;

use App\Events\SendPosCustomerSms;
use App\Services\PosCustomerSmsNotificationBuilder;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendPosCustomerSmsNotification
{
    public function handle(SendPosCustomerSms $event): void
    {
        // Throwable, not Exception: this runs after the till's response is
        // sent, and a gateway class that fails to load is an Error.
        try {
            app(PosCustomerSmsNotificationBuilder::class)->send((int) $event->info['user_id']);
        } catch (Throwable $exception) {
            Log::info($exception->getMessage());
        }
    }
}
