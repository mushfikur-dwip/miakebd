<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Posts the pending Events API events to TikTok. Scheduled every minute
 * (routes/console.php); the storefront only ever writes to `tiktok_events`.
 */
class SendTikTokEvents extends Command
{
    protected $signature = 'tiktok:send-events';

    protected $description = 'Send pending TikTok Events API events in batches, and prune old ones';

    public function handle(): int
    {
        // Through the fallback's lock, so a cron run and a storefront-driven
        // run can never post the same pending rows twice.
        $sent = \App\Support\ScheduleFallback::sendTikTokEvents();

        if ($sent > 0) {
            $this->info("Sent {$sent} event(s) to TikTok.");
        }

        return self::SUCCESS;
    }
}
