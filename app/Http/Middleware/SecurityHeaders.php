<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers the site was serving none of.
 *
 * Deliberately no Content-Security-Policy here. A useful one has to enumerate
 * every script, style and frame source the storefront and the payment gateways
 * actually use, and getting that wrong takes checkout down rather than making
 * it safer. It is worth doing, but it needs to be built from observed traffic,
 * not guessed at during a stock release.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Stops a browser second-guessing Content-Type. An uploaded file that
        // the server labels as an image is then never executed as script even
        // if its bytes look like HTML.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Clickjacking: without this the admin panel can be framed by another
        // site and an admin tricked into clicking controls they cannot see.
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Full URLs - including any order or token in a query string - were
        // being sent as the Referer to every third party the pages link out to.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        // Only on connections that are already HTTPS. Sent over plain HTTP the
        // header is ignored anyway, and asserting it from a non-secure request
        // is how a site locks itself out of its own HTTP fallback.
        //
        // One year, subdomains included, no preload - preload is a one-way
        // door that belongs to whoever owns the domain, not to this file.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
