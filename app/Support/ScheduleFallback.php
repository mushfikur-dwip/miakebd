<?php

namespace App\Support;

use App\Services\MetaConversionsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the scheduled jobs running on a server whose cron job does not.
 *
 * Three things only ever ran from `schedule:run`: sending the Meta Conversions
 * API outbox (every minute), the product feed for catalogue ads (hourly) and
 * the sitemap (daily). On 2026-09-25 the product feed was 21 hours old and the
 * sitemap had not been rebuilt for seven weeks - the cron was not running - so
 * every server-side ad event sat in `meta_events` until it was pruned unsent,
 * and Meta only ever saw what browsers without an ad blocker reported.
 *
 * So storefront traffic drives the same jobs as a fallback: after a response
 * has been sent (RunOverdueTasks::terminate), a job whose interval has passed
 * runs in that PHP process. The visitor never waits for it. Each job is claimed
 * with an atomic cache add, so a burst of requests starts it once.
 *
 * It stands down on its own when cron works: `schedule:run` writes a heartbeat
 * every minute (routes/console.php), and while that is fresh the fallback does
 * nothing, so the two never run the same job side by side.
 */
class ScheduleFallback
{
    /** Written by the scheduler each minute; see routes/console.php. */
    public const HEARTBEAT_KEY = 'schedule:heartbeat';

    /** How stale the heartbeat may get before the fallback takes over. */
    public const HEARTBEAT_GRACE = 180;

    /** Shared with `meta:send-events`, so the two never post the same rows. */
    public const META_LOCK = 'meta:sending';

    public static function heartbeat(): void
    {
        Cache::put(self::HEARTBEAT_KEY, time(), self::HEARTBEAT_GRACE * 4);
    }

    public static function cronIsAlive(): bool
    {
        $beat = (int) Cache::get(self::HEARTBEAT_KEY, 0);

        return $beat > 0 && (time() - $beat) < self::HEARTBEAT_GRACE;
    }

    /**
     * Name => [interval in seconds, what to run].
     *
     * @return array<string, array{0: int, 1: callable}>
     */
    public static function tasks(): array
    {
        return [
            'meta-events' => [60, fn () => self::sendMetaEvents()],
            'product-feed' => [3600, fn () => Artisan::call('feeds:products')],
            'sitemap' => [86400, fn () => Artisan::call('sitemap:generate')],
        ];
    }

    /**
     * Runs whichever jobs are due. Never throws: this happens after a page has
     * already been served, and nothing here is worth an error page.
     */
    public static function runDue(): void
    {
        try {
            if (self::cronIsAlive()) {
                return;
            }

            foreach (self::tasks() as $name => [$interval, $task]) {
                // add() is atomic, so of a hundred simultaneous requests only
                // one claims the slot; the rest move straight on.
                if (!Cache::add('schedule-fallback:' . $name, time(), $interval)) {
                    continue;
                }

                try {
                    // The feed and the sitemap walk the whole catalogue.
                    @set_time_limit(300);
                    $task();
                } catch (\Throwable $e) {
                    Log::warning("Scheduled task fallback '{$name}' failed: " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Scheduled task fallback skipped: ' . $e->getMessage());
        }
    }

    /**
     * One batch to Meta, under the same lock the artisan command takes. Two
     * senders reading the same pending rows would post them twice.
     *
     * @return int events Meta accepted
     */
    public static function sendMetaEvents(): int
    {
        $lock = Cache::lock(self::META_LOCK, 120);

        if (!$lock->get()) {
            return 0;
        }

        try {
            $meta = app(MetaConversionsService::class);
            $sent = $meta->sendPending();
            $meta->prune();

            return $sent;
        } finally {
            $lock->release();
        }
    }
}
