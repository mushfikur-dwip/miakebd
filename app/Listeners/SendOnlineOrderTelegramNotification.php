<?php

namespace App\Listeners;

use App\Events\SendOnlineOrderTelegram;
use App\Services\OnlineOrderTelegramNotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendOnlineOrderTelegramNotification
{
    public function handle(SendOnlineOrderTelegram $event): void
    {
        try {
            app(OnlineOrderTelegramNotificationService::class)->send((int)$event->info['order_id']);
        } catch (Throwable $exception) {
            Log::info($exception->getMessage());
        }
    }
}
