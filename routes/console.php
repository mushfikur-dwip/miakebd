<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Proof of life for App\Support\ScheduleFallback: while this is fresh, the
// storefront leaves the jobs below to the cron.
Schedule::call(fn () => \App\Support\ScheduleFallback::heartbeat())->name('schedule-heartbeat')->everyMinute();

Schedule::command('sitemap:generate')->dailyAt('02:00')->withoutOverlapping();

// Meta Conversions API: the storefront only stores events; this sends them in
// one batch a minute. See App\Services\MetaConversionsService.
Schedule::command('meta:send-events')->everyMinute()->withoutOverlapping(5);

// The same for TikTok's Events API. See App\Services\TikTokEventsService.
Schedule::command('tiktok:send-events')->everyMinute()->withoutOverlapping(5);

// The catalogue for Meta product ads and Google's free Shopping listings.
Schedule::command('feeds:products')->hourly()->withoutOverlapping();

// Tells Bing, Yandex, Seznam and Naver which pages changed. See App\Support\IndexNow.
Schedule::command('indexnow:submit')->hourly()->withoutOverlapping();
