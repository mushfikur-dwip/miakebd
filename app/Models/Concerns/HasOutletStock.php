<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Shared by Product and ProductVariation, both of which own stock rows
 * through the same `item` morph.
 */
trait HasOutletStock
{
    /**
     * Sums stock the same way `withSum('stockItems', 'quantity')` always has,
     * but optionally narrowed to one branch. It deliberately reuses the
     * `stock_items_sum_quantity` alias so every resource that already reads
     * that attribute keeps working - the POS gets the branch number and the
     * storefront gets the grand total from the same code path.
     *
     * $outletId null means "no branch filter", i.e. every outlet plus the
     * unassigned rows. That is what the public site must keep asking for.
     */
    public function scopeWithStockQuantity(Builder $query, $outletId = null): Builder
    {
        return $query->withSum([
            'stockItems as stock_items_sum_quantity' => function ($query) use ($outletId) {
                if (!blank($outletId)) {
                    $query->where('outlet_id', $outletId);
                }
            },
        ], 'quantity');
    }
}
