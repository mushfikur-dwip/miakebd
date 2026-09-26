<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the admin routes: any change an admin makes clears the storefront's
 * cached catalogue responses, so the shop shows it on the very next request.
 * See CachePublicResponse.
 */
class FlushPublicResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && $response->getStatusCode() < 400) {
            CachePublicResponse::flush();
        }

        return $response;
    }
}
