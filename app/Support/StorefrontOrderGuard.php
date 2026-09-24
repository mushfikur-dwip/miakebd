<?php

namespace App\Support;

use App\Enums\Ask;
use App\Models\Product;
use Exception;

/**
 * Refuses an online order that contains a POS-only product.
 *
 * Those products are stocked for the till and hidden from every storefront
 * listing, so a cart can only be holding one through a stale cart saved before
 * the product was switched over, a link someone kept, or a hand-made request.
 * This is the last place that can stop it becoming a paid online order.
 *
 * Deliberately NOT applied to POS or admin orders (OrderService): selling these
 * at the till is the entire point of the flag.
 */
class StorefrontOrderGuard
{
    /**
     * @param  array|object[]  $products  Decoded `products` payload.
     *
     * @throws Exception 422 when a line is not orderable online.
     */
    public static function assertNoPosOnlyProducts(array $products): void
    {
        if (blank($products)) {
            return;
        }

        $productIds = [];
        foreach ($products as $line) {
            $productId = (int) (is_array($line) ? ($line['product_id'] ?? 0) : ($line->product_id ?? 0));
            if ($productId > 0) {
                $productIds[] = $productId;
            }
        }

        if (blank($productIds)) {
            return;
        }

        // A variation belongs to a product, so checking the product covers the
        // variation lines too. withTrashed for the same reason OrderPriceGuard
        // uses it: whether a deleted product may be checked out is a separate
        // question, but a hidden one must be caught either way.
        $hidden = Product::withTrashed()
            ->whereIn('id', $productIds)
            ->where('pos_only', Ask::YES)
            ->exists();

        if ($hidden) {
            throw new Exception(trans('all.message.pos_only_product'), 422);
        }
    }
}
