<?php

namespace App\Jobs;

use App\Services\MetaConversionsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One Conversions API event.
 *
 * Dispatched with ->afterResponse(), so on this host - where the queue runs
 * synchronously - the call to Facebook happens once the customer already has
 * their page. Nothing in a checkout ever waits on an ad platform.
 */
class SendMetaConversionEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** One attempt: a late conversion event is worth less than a blocked queue. */
    public int $tries = 1;

    /** Public so tests can assert on exactly what would leave the server. */
    public function __construct(public array $event)
    {
    }

    public function handle(MetaConversionsService $meta): void
    {
        $meta->send($this->event);
    }
}
