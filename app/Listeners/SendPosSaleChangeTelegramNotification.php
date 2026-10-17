<?php

namespace App\Listeners;

use App\Events\SendPosSaleChangeTelegram;
use App\Services\PosSaleChangeTelegramNotificationService;
use Exception;
use Illuminate\Support\Facades\Log;

class SendPosSaleChangeTelegramNotification
{
    public function handle(SendPosSaleChangeTelegram $event): void
    {
        try {
            app(PosSaleChangeTelegramNotificationService::class)->send((int) $event->info['change_id']);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
        }
    }
}
