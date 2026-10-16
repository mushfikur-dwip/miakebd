<?php

namespace App\Support;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Stock;
use App\Services\ProductVariationService;
use Exception;

/**
 * Refuses an online order that asks for more than the shop has, while the
 * "stock cannot be zero" switch is on.
 *
 * The till was already guarded (StockGuard, called as the sale is written), but
 * the website was not guarded anywhere: checkout writes its stock rows INACTIVE
 * and the payment step flips them ACTIVE announce-only, so an online order
 * could take a branch as far below zero as the customer liked. This is the
 * missing half of that rule.
 *
 * Checked before the order is written, so a refused order leaves nothing
 * behind. The storefront already hides sold-out products, so reaching this is a
 * stale cart, a kept link or a hand-made request.
 *
 * Products the shop does not count (can_purchasable = NO) are exempt, exactly
 * as they are at the till.
 */
class StorefrontStockGuard
{
    /**
     * @param  array|object[]  $products  Decoded `products` payload.
     * @param  int|null  $outletId  The branch a pick-up order collects from;
     *                              null for delivery, which draws on the whole
     *                              shop rather than on one branch's shelf.
     *
     * @throws Exception 422 naming the item, the place and what is left.
     */
    public static function assertStockCovers(array $products, ?int $outletId = null): void
    {
        if (blank($products) || !app(StockPolicy::class)->cannotBeZero()) {
            return;
        }

        // Lines added up per item first: the same product twice in one cart is
        // one demand on the shelf, and checking each line alone would let a
        // cart of 1 + 1 through a branch holding only 1.
        $wanted = [];
        foreach ($products as $line) {
            $line        = (object) $line;
            $productId   = (int) ($line->product_id ?? 0);
            $variationId = (int) ($line->variation_id ?? 0);
            $quantity    = (float) ($line->quantity ?? 0);

            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $itemType = $variationId > 0 ? ProductVariation::class : Product::class;
            $itemId   = $variationId > 0 ? $variationId : $productId;
            $key      = $itemType . '|' . $itemId;

            $wanted[$key] ??= ['product_id' => $productId, 'item_type' => $itemType, 'item_id' => $itemId, 'quantity' => 0];
            $wanted[$key]['quantity'] += $quantity;
        }

        if (blank($wanted)) {
            return;
        }

        $products = Product::withTrashed()
            ->whereIn('id', array_column($wanted, 'product_id'))
            ->get()
            ->keyBy('id');

        foreach ($wanted as $line) {
            $product = $products->get($line['product_id']);
            if (!$product || (int) $product->can_purchasable === Ask::NO) {
                continue;
            }

            $held = self::held($line['item_type'], $line['item_id'], $outletId);
            if ($line['quantity'] <= $held) {
                continue;
            }

            throw new Exception(trans('all.message.stock_not_enough', [
                'name'   => self::itemName($product, $line),
                'branch' => $outletId ? (Outlet::find($outletId)?->name ?? '') : trans('all.label.online'),
                'left'   => max(0, $held),
            ]), 422);
        }
    }

    /**
     * What is available to this order. A pick-up order may only take what its
     * branch holds; a delivery order has no branch, so it draws on the shop's
     * whole stock - comparing it against one shelf would refuse every delivery.
     *
     * Only ACTIVE rows count: an online order still waiting for its payment
     * holds no stock, which is also why two customers can both reach checkout
     * for the last unit. The first to pay takes it.
     */
    private static function held(string $itemType, int $itemId, ?int $outletId): int
    {
        return (int) Stock::where('status', Status::ACTIVE)
            ->where('item_type', $itemType)
            ->where('item_id', $itemId)
            ->when($outletId !== null, fn($query) => $query->where('outlet_id', $outletId))
            ->sum('quantity');
    }

    private static function itemName(Product $product, array $line): string
    {
        if ($line['item_type'] === ProductVariation::class) {
            $variation = ProductVariation::find($line['item_id']);
            if ($variation) {
                return $product->name . ' (' . app(ProductVariationService::class)->ancestorsToString($variation) . ')';
            }
        }

        return $product->name;
    }
}
