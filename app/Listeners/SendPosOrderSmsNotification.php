<?php

namespace App\Listeners;

use App\Events\SendPosOrderSms;
use App\Services\PosOrderSmsNotificationBuilder;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendPosOrderSmsNotification
{
    public function handle(SendPosOrderSms $event): void
    {
        // Throwable, not Exception: this runs after the till's response is
        // sent, and a gateway class that fails to load is an Error.
        try {
            app(PosOrderSmsNotificationBuilder::class)->send((int) $event->info['order_id']);
        } catch (Throwable $exception) {
            Log::info($exception->getMessage());
        }
    }
}
