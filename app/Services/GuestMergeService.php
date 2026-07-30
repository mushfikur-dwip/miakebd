<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GuestMergeService
{
    /**
     * Tables whose rows follow the customer when guest records are absorbed
     * into a real account. Everything here is order-scoped or address-book
     * data a guest checkout can actually create.
     *
     * Deliberately absent:
     *   wallets       user_id carries a unique index, so moving a row would
     *                 collide with the target's own wallet.
     *   transactions  rows carry balance_before/balance_after; re-pointing them
     *                 would rewrite a real customer's wallet ledger. The order
     *                 payment is still reachable through transactions.order_id.
     *   wishlists, product_reviews, push_notifications, default_access
     *                 unreachable for a guest — those flows sit behind account
     *                 routes.
     */
    private const USER_OWNED_TABLES = [
        'orders',
        'order_addresses',
        'order_outlet_addresses',
        'order_coupons',
        'addresses',
        'return_orders',
        'return_and_refunds',
        'return_and_refund_products',
    ];

    /**
     * Re-points every guest-owned row at $target. The guest rows themselves are
     * left in place — other tables may reference them and an empty user row is
     * harmless.
     *
     * Transaction management is the caller's, so a merge and the account
     * upgrade that follows it commit or roll back together.
     */
    public function merge(User $target, Collection $guests): int
    {
        $guestIds = $guests->pluck('id')
            ->map(fn($id) => (int) $id)
            ->reject(fn($id) => $id === (int) $target->id)
            ->values()
            ->all();

        if (empty($guestIds)) {
            return 0;
        }

        $movedOrders = 0;

        foreach (self::USER_OWNED_TABLES as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $affected = DB::table($table)->whereIn('user_id', $guestIds)->update(['user_id' => $target->id]);

            if ($table === 'orders') {
                $movedOrders = $affected;
            }
        }

        // Cashback is credited to users.balance by OrderObserver, not to the
        // wallets table — so moving the orders alone strands the money on the
        // abandoned guest row. The customer would see the order history but not
        // the balance it earned.
        $strandedBalance = (float) DB::table('users')->whereIn('id', $guestIds)->sum('balance');

        if ($strandedBalance > 0) {
            DB::table('users')->where('id', $target->id)->increment('balance', $strandedBalance);
            DB::table('users')->whereIn('id', $guestIds)->update(['balance' => 0]);
        }

        // The leftover guest rows keep the same phone number, which collides
        // with every uniqueness check the customer's own profile later runs
        // against. They are emptied rather than deleted — other tables may
        // still reference them, and the rows carry no value once merged.
        DB::table('users')->whereIn('id', $guestIds)->update([
            'phone' => null,
            'country_code' => null,
        ]);

        return $movedOrders;
    }
}
