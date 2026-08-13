<?php

namespace App\Http\Middleware;

use App\Enums\Status;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
 *
 * ---------------------------------------------------------------------------
 * It blocks on status === INACTIVE, not on status !== ACTIVE.
 *
 * Those differ only for a value outside the enum, and that difference locked
 * this store's owner out of their own admin panel. `Status` holds ACTIVE = 5
 * and INACTIVE = 10; the deactivate control writes 10. A users.status holding
 * anything else — 1 from an older schema or a data import — is not a
 * deactivated account, but `!== ACTIVE` treated it as one, answered 401 and
 * deleted the token. Every admin endpoint failed at once, the storefront
 * wishlist and checkout failed with it, and logging back in did not help
 * because each new token was burned on its first admin request.
 *
 * Testing for the value that actually means "turned off" says what this is
 * for, and cannot be tripped by a legacy value it was never meant to judge.
 * ---------------------------------------------------------------------------
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (int) $user->status === Status::INACTIVE) {
            // Logged, because the consequences of this branch are severe and
            // it used to happen in total silence: 401 plus a deleted token,
            // with nothing written anywhere to say why.
            Log::warning('Account rejected by EnsureUserIsActive; token revoked.', [
                'user_id' => $user->id,
                'status'  => $user->status,
                'path'    => $request->path(),
            ]);

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
