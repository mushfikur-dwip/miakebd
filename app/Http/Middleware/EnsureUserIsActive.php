<?php

namespace App\Http\Middleware;

use App\Enums\Status;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-checks the account behind a Sanctum token on every request.
 *
 * `auth:sanctum` only proves the token exists and still resolves to a user. It
 * does not look at `users.status`, and it does not care whether the user still
 * holds a role. A Sanctum token is a bearer credential with no expiry here, so
 * once it is in a browser's localStorage it keeps working until it is deleted
 * server-side — deactivating the account or stripping its roles does nothing.
 *
 * That is the gap behind "the old administrator was removed but can still do
 * the job": their tab still held a valid token.
 *
 * Revoking tokens at the point of change (see AdministratorService) is the
 * primary fix. This middleware is the backstop that covers every other way an
 * account can be turned off — a direct DB edit, a future admin screen, a role
 * change made anywhere else — without each of those having to remember.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (int) $user->status !== Status::ACTIVE) {
            // Burn the credential on the way out, so a deactivated account
            // cannot keep retrying with the same token.
            $user->currentAccessToken()?->delete();

            return response()->json([
                'success' => false,
                'message' => 'This account is no longer active.',
            ], 401);
        }

        return $next($request);
    }
}
