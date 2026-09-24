<?php

namespace App\Http\Middleware;

use App\Enums\Ask;
use App\Enums\Role as EnumRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps shoppers out of /api/admin altogether.
 *
 * Before this, the admin group asked only for a valid Sanctum token and an
 * active account - and every customer has both. Guest checkout even hands a
 * token to anyone who types a phone number, with no OTP. The only thing between
 * the public and an admin endpoint was the `permission:` middleware each
 * controller lists for itself, method by method. A method missing from that
 * list was open to the internet: wallet credit, product discounts and the
 * subscriber mailer all were.
 *
 * This fails closed instead. Whatever a controller forgets, a caller must hold
 * at least one role other than Customer to reach it. The per-method permission
 * checks still decide what a staff member may do once inside.
 */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || (int) $user->is_guest === Ask::YES || !$this->hasStaffRole($user)) {
            return response()->json([
                'success' => false,
                'message' => 'User does not have the right permissions.',
            ], 403);
        }

        return $next($request);
    }

    // A role other than Customer, or a permission granted to the user directly
    // (an explicit staff grant). Permissions a user merely inherits through the
    // Customer role do not count, so editing that role can never open this.
    private function hasStaffRole($user): bool
    {
        return $user->roles->contains(fn($role) => (int) $role->id !== EnumRole::CUSTOMER)
            || $user->permissions->isNotEmpty();
    }
}
