<?php

namespace App\Http\Resources;

use App\Enums\Ask;
use App\Libraries\AppLibrary;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One tile of the POS product grid - exactly the fields the till renders.
 *
 * Deliberately not ProductAdminResource. That one serves the admin product
 * screens: it ships each product's description and shipping-and-return HTML,
 * its tags, taxes and barcode, and an all-time order total it computes by
 * loading every order line. A till showing the whole catalogue needs none of it.
 *
 * Built for the query in ProductService::posList(). `variations_count`,
 * `stock_items_sum_quantity` and the rating sub-selects must already be on the
 * row: without `variations_count` every product is silently priced as if it
 * had no variations.
 */
class PosProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        $price = $this->variations_count > 0 ? $this->variation_price : $this->selling_price;

        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'cover'             => $this->cover,
            // Quantity at the branch the till passed, or the total across
            // branches when it passed none.
            'stock'             => (int) $this->stock_items_sum_quantity,
            'flash_sale'        => $this->add_to_flash_sale == Ask::YES,
            'is_offer'          => AppLibrary::isBetweenDate($this->offer_start_date, $this->offer_end_date),
            'currency_price'    => AppLibrary::currencyAmountFormat($price),
            'discounted_price'  => AppLibrary::currencyAmountFormat($price - (($price / 100) * $this->discount)),
            'rating_star'       => $this->rating_star,
            'rating_star_count' => $this->rating_star_count,
        ];
    }
}
