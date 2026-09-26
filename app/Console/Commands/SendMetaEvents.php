<?php

namespace App\Console\Commands;

use App\Services\MetaConversionsService;
use Illuminate\Console\Command;

/**
 * Posts the pending Conversions API events to Meta.
 *
 * Scheduled every minute (routes/console.php). The storefront only ever writes
 * events to `meta_events`; this is the one place that talks to Facebook, so a
 * slow or failing Graph API costs a background minute, never a customer's page.
 */
class SendMetaEvents extends Command
{
    protected $signature = 'meta:send-events';

    protected $description = 'Send pending Meta Conversions API events in a batch, and prune old ones';

    public function handle(MetaConversionsService $meta): int
    {
        // Through the fallback's lock, so a cron run and a storefront-driven
        // run can never post the same pending rows twice.
        $sent = \App\Support\ScheduleFallback::sendMetaEvents();

        if ($sent > 0) {
            $this->info("Sent {$sent} event(s) to Meta.");
        }

        return self::SUCCESS;
    }
}
