<?php

namespace App\Http\Resources;

use App\Enums\Activity;
use App\Enums\Ask;
use App\Libraries\AppLibrary;
use Illuminate\Http\Resources\Json\JsonResource;

class SimpleProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        $price = count($this->variations) > 0 ? $this->variation_price : $this->selling_price;
        $discountedPrice = $price - (($price / 100) * $this->discount);
        
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'slug'              => $this->slug,
            'sku'               => $this->sku,
            'currency_price'    => AppLibrary::currencyAmountFormat($price),
            'flat_price'        => AppLibrary::flatAmountFormat($price),
            'convert_price'     => AppLibrary::convertAmountFormat($price),
            'cover'             => $this->cover,
            'flash_sale'        => $this->add_to_flash_sale == Ask::YES,
            'is_offer'          => AppLibrary::isBetweenDate($this->offer_start_date, $this->offer_end_date),
            'discounted_price'  => AppLibrary::currencyAmountFormat($discountedPrice),
            'flat_discounted_price' => AppLibrary::flatAmountFormat($discountedPrice),
            'discount'          => $this->discount,
            'stock'             => $this->listingStock(),
            // A product with variations cannot be added from a card - there is
            // no size or shade chosen - so the card sends the shopper to its
            // page instead.
            'has_variations'    => count($this->variations) > 0,
            'taxes'             => ProductTaxResource::collection($this->taxes),
            // Product-wise shipping reads this from the cart line. Without it a
            // product added from a listing shipped free in the cart, while the
            // server (OrderTotals) charges the product's real cost.
            'shipping'          => [
                'shipping_type'                => $this->shipping_type,
                'shipping_cost'                => $this->shipping_cost,
                'is_product_quantity_multiply' => $this->is_product_quantity_multiply,
            ],
            'maximum_purchase_quantity' => $this->maximum_purchase_quantity,
            'rating_star'       => $this->rating_star,
            'rating_star_count' => (int) $this->rating_star_count,
            'wishlist'          => (bool)$this->wishlist,
        ];
    }

    /**
     * What the product page would call this product's stock, or null when the
     * listing query did not load what that takes.
     *
     * Listings used to send 0 for every product (nothing selected a stock
     * figure) and the card replaced it with 100, so an out-of-stock product
     * could be added from any listing and was only refused at the very last
     * step of checkout. The rule is SimpleProductDetailsResource's; it is
     * applied only when the stock sum and both flags were actually selected,
     * because a missing column would read as "out of stock" and hide
     * products that are not.
     */
    private function listingStock(): ?int
    {
        $attributes = $this->resource->getAttributes();

        foreach (['stock_items_sum_quantity', 'show_stock_out', 'can_purchasable'] as $column) {
            if (!array_key_exists($column, $attributes)) {
                return null;
            }
        }

        if ($this->show_stock_out != Activity::DISABLE) {
            return 0;
        }

        return $this->can_purchasable == Ask::NO
            ? (int) env('NON_PURCHASE_QUANTITY')
            : (int) $this->stock_items_sum_quantity;
    }
}
