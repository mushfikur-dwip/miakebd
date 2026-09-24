<?php

namespace App\Support;

use App\Enums\Ask;
use App\Enums\DiscountType;
use App\Enums\OrderType;
use App\Enums\ShippingMethod;
use App\Enums\ShippingType;
use App\Libraries\AppLibrary;
use App\Models\Address;
use App\Models\Coupon;
use App\Models\OrderArea;
use App\Models\OrderCoupon;
use App\Models\Product;
use Carbon\Carbon;
use Dipokhalder\Settings\Facades\Settings;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * The order-level money - discount, tax, shipping and total - worked out on
 * the server.
 *
 * OrderPriceGuard proves each line price and the subtotal. Everything above
 * the subtotal still arrived from the browser and was written as sent: an
 * honest cart posted with "total": 1 was charged 1 taka by bKash and collected
 * as 1 taka by the COD rider. The same went for an invented discount with no
 * coupon behind it, and for a coupon past its dates or its per-customer limit,
 * which this endpoint never re-checked.
 *
 * The formula is the checkout screen's:
 *     total = subtotal - coupon discount + tax + shipping
 * and the browser's total must agree with it. A disagreement is refused rather
 * than silently overwritten, because the payment page auto-submits without
 * showing an amount - replacing the figure would charge the customer a number
 * they never saw.
 */
class OrderTotals
{
    /** Largest quantity a single storefront line may carry. */
    private const MAX_QUANTITY = 10000;

    /**
     * Every line must be a positive whole quantity.
     *
     * Nothing checked this. A line of quantity -3 multiplied cleanly through
     * OrderPriceGuard, took three items' worth off the subtotal, and wrote a
     * stock row that ADDED three to inventory.
     *
     * @throws Exception
     */
    public static function assertQuantities(array $products): void
    {
        foreach ($products as $line) {
            $quantity = $line->quantity ?? null;

            if (
                !is_numeric($quantity)
                || (float) $quantity != (int) $quantity
                || (int) $quantity < 1
                || (int) $quantity > self::MAX_QUANTITY
            ) {
                throw new Exception(trans('all.message.invalid_order_quantity'), 422);
            }
        }
    }

    /**
     * @param  array  $attributes  The validated order request.
     * @param  array  $products    Decoded `products` lines, already through OrderPriceGuard.
     * @return array{discount: float, tax: float, shipping_charge: float, total: float, lines: array}
     *
     * @throws Exception 422 when the browser's total disagrees, or the coupon is not usable.
     */
    public static function resolve(array $attributes, array $products, int $userId): array
    {
        $decimals = (int) env('CURRENCY_DECIMAL_POINT', 2);

        $subtotal = 0.0;
        $tax      = 0.0;
        $lines    = [];

        foreach ($products as $index => $line) {
            // The line price is the one OrderPriceGuard has just proven, so the
            // same rounded figure the browser multiplied is used here.
            $price    = round((float) $line->price, $decimals);
            $quantity = (int) $line->quantity;
            $product  = Product::withTrashed()->with('taxes.tax')->find((int) $line->product_id);

            [$lineTax, $lineTaxes] = self::lineTax($product, $price, $quantity);

            $subtotal += $price * $quantity;
            $tax      += $lineTax;

            $lines[$index] = [
                'product'   => $product,
                'quantity'  => $quantity,
                'subtotal'  => $price * $quantity,
                'tax'       => $lineTax,
                'taxes'     => $lineTaxes,
                'total'     => ($price * $quantity) + $lineTax,
            ];
        }

        $shipping = self::shipping($attributes, $lines);
        $discount = self::discount($attributes, $subtotal, $userId);
        $total    = $subtotal - $discount + $tax + $shipping;

        $claimed = (float) ($attributes['total'] ?? 0);
        if (abs(round($claimed, $decimals) - round($total, $decimals)) > 0.01) {
            Log::warning('Order refused: submitted total does not match the server calculation.', [
                'user_id'   => $userId,
                'submitted' => [
                    'subtotal'        => $attributes['subtotal'] ?? null,
                    'discount'        => $attributes['discount'] ?? null,
                    'tax'             => $attributes['tax'] ?? null,
                    'shipping_charge' => $attributes['shipping_charge'] ?? null,
                    'total'           => $claimed,
                ],
                'expected'  => compact('subtotal', 'discount', 'tax', 'shipping', 'total'),
            ]);

            throw new Exception(trans('all.message.order_price_changed'), 422);
        }

        return [
            'discount'        => round($discount, $decimals),
            'tax'             => round($tax, $decimals),
            'shipping_charge' => round($shipping, $decimals),
            'total'           => round($total, $decimals),
            'lines'           => $lines,
        ];
    }

    /**
     * What a coupon takes off a given base. Shared with CouponCheckResource,
     * which quotes the same figure to the checkout screen, so the two cannot
     * drift apart.
     */
    public static function couponDiscount(Coupon $coupon, float $base): float
    {
        $amount = (int) $coupon->discount_type === DiscountType::FIXED
            ? (float) $coupon->discount
            : $base * (float) $coupon->discount / 100;

        // Same cap test the quote has always used, null maximum included.
        if ($amount > $coupon->maximum_discount) {
            return (float) $coupon->maximum_discount;
        }

        return $amount;
    }

    /**
     * Tax per line, from the product's own tax rows - the checkout screen's
     * arithmetic (percentage of the unit price, times quantity), fed with the
     * database's rates rather than the ones the browser carried.
     */
    private static function lineTax(?Product $product, float $price, int $quantity): array
    {
        $perUnit = 0.0;
        $taxes   = [];

        foreach ($product?->taxes ?? [] as $productTax) {
            $rate = (float) ($productTax->tax?->tax_rate ?? 0);
            if ($rate <= 0) {
                continue;
            }

            $amount   = ($price / 100) * $rate;
            $perUnit += $amount;
            $taxes[]  = [
                'id'         => $productTax->tax->id,
                'name'       => $productTax->tax->name,
                'code'       => $productTax->tax->code,
                'tax_rate'   => $rate,
                'tax_amount' => $amount,
            ];
        }

        return [$perUnit * $quantity, $taxes];
    }

    /**
     * The checkout screen's shipping rules, read from the same settings and
     * order areas it uses. Pickup orders carry no shipping.
     */
    private static function shipping(array $attributes, array $lines): float
    {
        if ((int) ($attributes['order_type'] ?? 0) !== OrderType::DELIVERY) {
            return 0.0;
        }

        // A fresh group() for every read: the settings package forgets the
        // group after each get(), so a reused handle reads the second key from
        // the default group and gets null.
        $setting = fn(string $key) => Settings::group('shipping_setup')->get($key);
        $method  = (int) $setting('shipping_setup_method');

        if ($method === ShippingMethod::FLAT_WISE) {
            return (float) $setting('shipping_setup_flat_rate_wise_cost');
        }

        if ($method === ShippingMethod::PRODUCT_WISE) {
            $cost = 0.0;
            foreach ($lines as $line) {
                $product = $line['product'];
                if ($product && (int) $product->shipping_type === ShippingType::FLAT_RATE) {
                    $cost += (int) $product->is_product_quantity_multiply === Ask::YES
                        ? (float) $product->shipping_cost * $line['quantity']
                        : (float) $product->shipping_cost;
                }
            }

            return $cost;
        }

        if ($method === ShippingMethod::AREA_WISE) {
            $address = Address::find($attributes['shipping_id'] ?? 0);
            $cost    = (float) $setting('shipping_setup_area_wise_default_cost');

            if ($address) {
                // Same order the storefront lists them in, and the same rule:
                // the last matching area wins.
                foreach (OrderArea::orderBy('id', 'desc')->get() as $area) {
                    if ($area->country === $address->country && $area->state === $address->state) {
                        $cost = (float) $area->shipping_cost;
                    }
                }
            }

            return $cost;
        }

        return 0.0;
    }

    /**
     * The coupon discount this order may carry.
     *
     * The coupon is re-checked here - dates, minimum order, per-customer
     * limit - because only coupon-checking looked at those, and a crafted
     * order could send any coupon_id with any discount.
     *
     * A percentage coupon is quoted when it is applied, so a cart that grows
     * afterwards still carries the smaller, older figure. That is allowed: the
     * order may take anything up to what the coupon is worth on today's
     * subtotal, never more.
     *
     * @throws Exception
     */
    private static function discount(array $attributes, float $subtotal, int $userId): float
    {
        $couponId = (int) ($attributes['coupon_id'] ?? 0);
        if ($couponId <= 0) {
            return 0.0;
        }

        $coupon = Coupon::find($couponId);
        if (!$coupon) {
            throw new Exception(trans('all.message.coupon_not_exist'), 422);
        }

        $now = strtotime(Carbon::now());
        if (strtotime($coupon->start_date) > $now || strtotime($coupon->end_date) < $now) {
            throw new Exception(trans('all.message.coupon_date_expired'), 422);
        }

        if ($coupon->minimum_order > $subtotal) {
            throw new Exception(trans('all.message.minimum_order_amount') . AppLibrary::convertAmountFormat($coupon->minimum_order), 422);
        }

        if ($coupon->limit_per_user <= OrderCoupon::where(['user_id' => $userId, 'coupon_id' => $coupon->id])->count()) {
            throw new Exception(trans('all.message.coupon_limit_exceeded'), 422);
        }

        $allowed = AppLibrary::convertAmountFormat(self::couponDiscount($coupon, $subtotal));
        $claimed = max(0.0, (float) ($attributes['discount'] ?? 0));

        return min($claimed, $allowed);
    }
}
