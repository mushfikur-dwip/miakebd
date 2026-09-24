<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiKeyMiddleware
{

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Not a secret - the key ships inside the public JS bundle - so this only
        // keeps casual scripts off the auth routes. Still: config() rather than
        // env() (env() is null under config:cache, and '' == null let an empty
        // header through), a non-empty key, and a constant-time comparison.
        $expected = (string) config('app.api_key');
        $given    = (string) $request->header('x-api-key', '');

        if ($expected !== '' && $given !== '' && hash_equals($expected, $given)) {
            return $next($request);
        }
        return response()->json(trans('all.message.invalid_api_key'), 400);
    }
}
