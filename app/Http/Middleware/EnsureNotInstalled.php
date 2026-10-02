<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The web installer only exists until the shop is installed.
 *
 * InstallerController used to guard itself with Redirect::to(...)->send() in
 * its constructor. send() flushes the redirect and returns - PHP then carried
 * on into the action, so anyone could post the database step on the live site
 * and repoint its .env at a server of their choosing (followed by
 * migrate:fresh), or re-run the final step with a plain GET.
 *
 * As route middleware this runs before the controller is ever built, and a 404
 * says what is true: there is no installer here.
 */
class EnsureNotInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(file_exists(storage_path('installed')), 404);

        return $next($request);
    }
}
