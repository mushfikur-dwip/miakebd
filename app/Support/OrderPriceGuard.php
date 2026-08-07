<?php

namespace App\Support;

use App\Libraries\AppLibrary;
use App\Models\Campaign;
use App\Models\CampaignProduct;
use App\Models\Product;
use App\Models\ProductVariation;
use Exception;

/**
 * Recomputes every submitted order line from the database and rejects the
 * order if the browser's price does not match.
 *
 * Why this exists: FrontendOrderService writes `price`, `subtotal` and `total`
 * straight from `$request->products`, so before this class a crafted request
 * could order any item at any price. It is also what makes campaign pricing
 * trustworthy — a campaign line only reaches the order because the browser
 * sends its special price, so something has to confirm that price was real and
 * is still running.
 *
 * Deliberately NOT applied to POS/admin orders (OrderService): staff there set
 * prices by hand, which is the whole point of that screen.
 *
 * Scope note: this validates per-line prices and the order subtotal. Tax,
 * shipping and the order `total` are still computed client-side and sent as-is
 * — a separate pre-existing gap, untouched here.
 */
class OrderPriceGuard
{
    /** Cart lines added outside any campaign. */
    private const CATALOGUE_SOURCE = 'catalogue';

    /**
     * @param  array|object[]  $products  Decoded `products` payload.
     *
     * @throws Exception 422 when any line disagrees with the database.
     */
    public static function assertPricesAreGenuine(array $products, $submittedSubtotal = null): void
    {
        if (blank($products)) {
            return;
        }

        $decimals        = (int) env('CURRENCY_DECIMAL_POINT', 2);
        $expectedSubtotal = 0.0;

        foreach ($products as $line) {
            $productId   = (int) ($line->product_id ?? 0);
            $variationId = (int) ($line->variation_id ?? 0);
            $quantity    = (float) ($line->quantity ?? 0);
            $sentPrice   = (float) ($line->price ?? 0);
            $priceSource = (string) ($line->price_source ?? self::CATALOGUE_SOURCE);

            // withTrashed on purpose. This guard is about prices only; whether
            // a soft-deleted product may still be checked out is a separate
            // question that was never enforced here, and quietly starting to
            // reject those orders would be a behaviour change nobody asked for.
            $product = Product::withTrashed()->find($productId);

            if (!$product) {
                throw new Exception(trans('all.message.order_price_changed'), 422);
            }

            $expectedPrice = self::expectedPrice($product, $variationId, $priceSource);

            // Both sides rounded to the store's currency precision before
            // comparison. The browser only ever receives a value already
            // formatted to `decimals`, so comparing raw floats would reject
            // honest orders on a fraction of a paisa.
            if (!self::sameMoney($sentPrice, $expectedPrice, $decimals)) {
                throw new Exception(trans('all.message.order_price_changed'), 422);
            }

            $lineSubtotal = round($expectedPrice, $decimals) * $quantity;

            // Catches a tampered subtotal on a line whose unit price is honest.
            if (!self::sameMoney((float) ($line->subtotal ?? 0), $lineSubtotal, $decimals)) {
                throw new Exception(trans('all.message.order_price_changed'), 422);
            }

            $expectedSubtotal += $lineSubtotal;
        }

        // orders.subtotal is what the line prices are supposed to add up to.
        // Without this a valid set of lines could still carry an invented
        // order-level subtotal.
        if ($submittedSubtotal !== null && !self::sameMoney((float) $submittedSubtotal, $expectedSubtotal, $decimals)) {
            throw new Exception(trans('all.message.order_price_changed'), 422);
        }
    }

    /**
     * The only price this line is allowed to have been sold at.
     *
     * @throws Exception
     */
    private static function expectedPrice(Product $product, int $variationId, string $priceSource): float
    {
        if ($priceSource !== self::CATALOGUE_SOURCE) {
            return self::campaignPrice($product, $variationId, $priceSource);
        }

        return self::cataloguePrice($product, $variationId);
    }

    /**
     * Campaign lines are priced by campaign_products.special_price, and only
     * while that campaign is inside its active window.
     *
     * A campaign that ended while the cart sat in localStorage therefore fails
     * here rather than silently ordering at yesterday's price — which is the
     * stale-cart case this validation exists for.
     *
     * @throws Exception
     */
    private static function campaignPrice(Product $product, int $variationId, string $priceSource): float
    {
        // "campaign:<id>" — the same key the cart uses to keep a campaign line
        // separate from a normally priced one.
        if (!preg_match('/^campaign:(\d+)$/', $priceSource, $matches)) {
            throw new Exception(trans('all.message.order_price_changed'), 422);
        }

        // Campaign pages add whole products, never a chosen variation, so a
        // variation id on a campaign line did not come from the storefront.
        if ($variationId > 0) {
            throw new Exception(trans('all.message.order_price_changed'), 422);
        }

        $campaign = Campaign::running()->find((int) $matches[1]);

        if (!$campaign) {
            throw new Exception(trans('all.message.campaign_price_expired'), 422);
        }

        $campaignProduct = CampaignProduct::where('campaign_id', $campaign->id)
            ->where('product_id', $product->id)
            ->first();

        if (!$campaignProduct) {
            throw new Exception(trans('all.message.campaign_price_expired'), 422);
        }

        return (float) $campaignProduct->special_price;
    }

    /**
     * The retail price, derived the same way the product resources derive it:
     * the variation's own price when one was chosen, otherwise the product's
     * variation_price if it has variations at all and selling_price if not —
     * then the offer discount, but only inside the offer window.
     *
     * @throws Exception
     */
    private static function cataloguePrice(Product $product, int $variationId): float
    {
        if ($variationId > 0) {
            $variation = ProductVariation::where('id', $variationId)
                ->where('product_id', $product->id)
                ->first();

            // A variation id belonging to a different product is tampering,
            // not a stale cart.
            if (!$variation) {
                throw new Exception(trans('all.message.order_price_changed'), 422);
            }

            $base = (float) $variation->price;
        } else {
            $base = $product->variations()->exists()
                ? (float) $product->variation_price
                : (float) $product->selling_price;
        }

        if (AppLibrary::isBetweenDate($product->offer_start_date, $product->offer_end_date)) {
            $base = $base - (($base / 100) * (float) $product->discount);
        }

        return $base;
    }

    /** Equal once both sides are rounded to the store's currency precision. */
    private static function sameMoney(float $a, float $b, int $decimals): bool
    {
        // Half a unit of the smallest denomination, so genuine float noise
        // passes and a real price difference never does.
        $tolerance = 0.5 / (10 ** $decimals);

        return abs(round($a, $decimals) - round($b, $decimals)) < $tolerance;
    }
}
