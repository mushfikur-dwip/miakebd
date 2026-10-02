<?php

namespace App\Console\Commands;

use App\Support\IndexNow;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Hourly (routes/console.php, and ScheduleFallback when the cron is down):
 * announce the pages that changed since the last successful run. The first
 * run - or --all - announces every live page.
 */
class IndexNowSubmit extends Command
{
    protected $signature = 'indexnow:submit {--all : Submit every live page, not only what changed since the last run}';

    protected $description = 'Tell IndexNow search engines (Bing, Yandex, Seznam, Naver) which pages are new, changed or gone';

    public function handle(): int
    {
        if (!IndexNow::enabled()) {
            $this->info('IndexNow disabled (APP_ENV=' . app()->environment() . ').');

            return self::SUCCESS;
        }

        $lastRun   = Cache::get(IndexNow::LAST_RUN_KEY);
        $since     = ($this->option('all') || !$lastRun) ? null : Carbon::parse($lastRun);
        // Taken before reading, so an edit made while this runs is in the next run.
        $startedAt = now();
        $urls      = IndexNow::urls($since);

        if ($urls === []) {
            Cache::forever(IndexNow::LAST_RUN_KEY, $startedAt->toIso8601String());
            $this->info('Nothing changed.');

            return self::SUCCESS;
        }

        if (!IndexNow::submit($urls)) {
            // Last run left as it was: the same window is tried again next time.
            $this->error('IndexNow did not accept the submission; it will be retried on the next run.');

            return self::FAILURE;
        }

        Cache::forever(IndexNow::LAST_RUN_KEY, $startedAt->toIso8601String());
        $this->info('Submitted ' . count($urls) . ' URLs to IndexNow.');

        return self::SUCCESS;
    }
}
