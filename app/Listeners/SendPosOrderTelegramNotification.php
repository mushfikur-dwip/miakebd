<?php

namespace App\Listeners;

use App\Events\SendPosOrderTelegram;
use App\Services\PosOrderTelegramNotificationService;
use Exception;
use Illuminate\Support\Facades\Log;

class SendPosOrderTelegramNotification
{
    public function handle(SendPosOrderTelegram $event): void
    {
        try {
            app(PosOrderTelegramNotificationService::class)->send((int)$event->info['order_id']);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
        }
    }
}
