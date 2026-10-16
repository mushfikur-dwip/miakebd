<?php

namespace App\Listeners;

use App\Events\SendLowStockTelegram;
use App\Services\LowStockTelegramNotificationService;
use Exception;
use Illuminate\Support\Facades\Log;

class SendLowStockTelegramNotification
{
    public function handle(SendLowStockTelegram $event): void
    {
        try {
            app(LowStockTelegramNotificationService::class)->send($event->info);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
        }
    }
}
