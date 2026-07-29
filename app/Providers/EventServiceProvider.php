<?php

namespace App\Providers;

use App\Events\SendEmailCode;
use App\Events\SendOrderGotMail;
use App\Events\SendOrderGotPush;
use App\Events\SendOrderGotSms;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Events\SendOrderSms;
use App\Events\SendPosOrderTelegram;
use App\Events\SendSmsCode;
use App\Events\SendVerifyEmailCode;
use App\Listeners\SendEmailCodeNotification;
use App\Listeners\SendOrderGotMailNotification;
use App\Listeners\SendOrderGotPushNotification;
use App\Listeners\SendOrderGotSmsNotification;
use App\Listeners\SendOrderMailNotification;
use App\Listeners\SendOrderPushNotification;
use App\Listeners\SendOrderSmsNotification;
use App\Listeners\SendPosOrderTelegramNotification;
use App\Listeners\SendSmsCodeNotification;
use App\Listeners\SendVerifyEmailCodeNotification;
use App\Models\Order;
use App\Observers\OrderObserver;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        SendSmsCode::class => [
            SendSmsCodeNotification::class,
        ],
        SendEmailCode::class => [
            SendEmailCodeNotification::class,
        ],
        SendVerifyEmailCode::class => [
            SendVerifyEmailCodeNotification::class,
        ],
        SendOrderMail::class => [
            SendOrderMailNotification::class,
        ],
        SendOrderSms::class => [
            SendOrderSmsNotification::class,
        ],
        SendOrderPush::class => [
            SendOrderPushNotification::class,
        ],
        SendOrderGotMail::class => [
            SendOrderGotMailNotification::class,
        ],
        SendOrderGotSms::class => [
            SendOrderGotSmsNotification::class,
        ],
        SendOrderGotPush::class => [
            SendOrderGotPushNotification::class,
        ],
        SendPosOrderTelegram::class => [
            SendPosOrderTelegramNotification::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        Order::observe(OrderObserver::class);
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
