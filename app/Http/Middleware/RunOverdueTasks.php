<?php

namespace App\Http\Middleware;

use App\Support\ScheduleFallback;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives the scheduled jobs a heartbeat from ordinary traffic when the server's
 * cron is not running. All the work is in terminate(), which Laravel calls
 * after the response has been flushed to the browser - see ScheduleFallback.
 */
class RunOverdueTasks
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        // Only once the visitor has their page: an error response is left
        // alone, as is anything under test, where it would add noise.
        if ($response->getStatusCode() >= 500 || app()->runningUnitTests()) {
            return;
        }

        ScheduleFallback::runDue();
    }
}
