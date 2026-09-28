<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * A save sent again with the same X-Idempotency-Key is not saved again.
 *
 * On a slow line the answer to a save can be lost after the server has done
 * the work; the page then sends the same request again with the same key, and
 * gets the first answer back instead of a second entry. A request still being
 * worked on when its copy arrives gets 409. Only successful answers are kept,
 * so a request that was refused (wrong PIN, missing field) can be fixed and
 * sent again with the same key.
 */
class Idempotent
{
    public const HEADER = 'X-Idempotency-Key';

    private const KEEP_MINUTES = 15;

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header(self::HEADER, '');

        if ($key === '' || $request->isMethodSafe() || !preg_match('/^[A-Za-z0-9_-]{1,100}$/', $key)) {
            return $next($request);
        }

        // Per person and per action: the same key from someone else, or for
        // another action, is a different request.
        $cacheKey = 'idempotent:' . sha1(($request->user()?->getAuthIdentifier() ?? 'guest') . '|' . $request->method() . '|' . $request->path() . '|' . $key);

        if ($replay = $this->replay($cacheKey)) {
            return $replay;
        }

        $lock = Cache::lock($cacheKey . ':lock', 60);
        if (!$lock->get()) {
            return response(['status' => false, 'message' => trans('all.message.request_in_progress')], 409);
        }

        try {
            // It may have finished while this copy waited for the lock.
            if ($replay = $this->replay($cacheKey)) {
                return $replay;
            }

            $response = $next($request);

            if ($response->isSuccessful()) {
                Cache::put($cacheKey, [
                    'status'  => $response->getStatusCode(),
                    'type'    => $response->headers->get('Content-Type'),
                    'content' => $response->getContent(),
                ], now()->addMinutes(self::KEEP_MINUTES));
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function replay(string $cacheKey): ?Response
    {
        $saved = Cache::get($cacheKey);
        if (!is_array($saved)) {
            return null;
        }

        return response($saved['content'], $saved['status'])
            ->header('Content-Type', $saved['type'] ?: 'application/json')
            ->header('X-Idempotent-Replay', '1');
    }
}
