<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Short-lived shared copies of the storefront's public catalogue responses.
 *
 * Every page load asks the API for the same menus, sliders, settings, category
 * tree and product lists - about twenty requests on the home page - and each
 * one opened a MySQL connection. When an ad sends a burst of visitors those
 * connections are what the host starts refusing ("[2002] Operation not
 * permitted", shown to shoppers as random "A database error occurred"). The
 * answers are the same for every anonymous visitor, so the first request
 * builds one and the rest, for a minute or two, are read from the cache
 * without touching the database at all.
 *
 * Only anonymous requests share a copy. A signed-in customer's product lists
 * carry their own wishlist flags, so any request with a bearer token goes
 * straight through and is never stored.
 *
 * Anything an admin changes clears every copy at once (FlushPublicResponses on
 * the admin routes bumps the version in the key), so a new price or banner is
 * live on the next request rather than when the copy expires. What is left to
 * the timer is stock sold through checkout, which is re-checked when the order
 * is placed anyway.
 */
class CachePublicResponse
{
    public const VERSION_KEY = 'public-api:version';

    public function handle(Request $request, Closure $next, int $seconds = 120): Response
    {
        if (!$this->cacheable($request)) {
            return $next($request);
        }

        $key = $this->key($request);

        try {
            $hit = Cache::get($key);
        } catch (\Throwable $e) {
            $hit = null;
        }

        if (is_array($hit) && isset($hit['body'])) {
            return response($hit['body'], 200, [
                'Content-Type' => $hit['type'] ?? 'application/json',
                'X-Cache'      => 'HIT',
            ]);
        }

        $response = $next($request);

        if ($response->getStatusCode() === 200 && !$response->headers->has('Set-Cookie')) {
            try {
                Cache::put($key, [
                    'body' => $response->getContent(),
                    'type' => $response->headers->get('Content-Type', 'application/json'),
                ], $seconds);
            } catch (\Throwable $e) {
                // A full disk or a broken cache must never cost the shopper
                // their page: this response is still perfectly good.
            }

            $response->headers->set('X-Cache', 'MISS');
        }

        return $response;
    }

    /** Clears every stored copy, by moving every key to a new version. */
    public static function flush(): void
    {
        try {
            Cache::forever(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 1) + 1);
        } catch (\Throwable $e) {
            // Nothing to do: the copies still expire on their own.
        }
    }

    private function cacheable(Request $request): bool
    {
        // POST only for the listing endpoints that read with it
        // (category-wise-products); the route decides which those are.
        if (!in_array($request->method(), ['GET', 'HEAD', 'POST'], true)) {
            return false;
        }

        return blank($request->bearerToken());
    }

    private function key(Request $request): string
    {
        $version = (int) Cache::get(self::VERSION_KEY, 1);

        return 'public-api:' . $version . ':' . sha1(implode('|', [
            $request->method(),
            $request->getRequestUri(),
            $request->getContent(),
            (string) $request->header('x-localization', ''),
            app()->getLocale(),
        ]));
    }
}
