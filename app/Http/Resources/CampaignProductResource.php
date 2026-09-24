<?php

namespace App\Http\Resources;

use App\Libraries\AppLibrary;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignProductResource extends JsonResource
{
    /**
     * A product card on a campaign page.
     *
     * The special price is scoped to THIS page. Every other surface — the main
     * listing, product detail, search, category pages, and the Product JSON-LD
     * in SeoSchema — keeps reading products.selling_price and is untouched by
     * campaigns. That is deliberate: a global override would put a price in
     * front of Google that disagrees with the product page's own schema.
     *
     * The key names mirror SimpleProductResource so ProductListComponent can
     * render this without a campaign-specific branch. `is_offer` is forced
     * true because the special price IS the offer here; it is what makes the
     * card strike the retail price through.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function toArray($request): array
    {
        // The retail price, shown struck through. Never overwritten.
        $retailPrice  = count($this->variations) > 0 ? $this->variation_price : $this->selling_price;
        $specialPrice = (float) $this->pivot->special_price;

        return [
            'id'   => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sku'  => $this->sku,

            'currency_price' => AppLibrary::currencyAmountFormat($retailPrice),
            'flat_price'     => AppLibrary::flatAmountFormat($retailPrice),
            'convert_price'  => AppLibrary::convertAmountFormat($retailPrice),

            'discounted_price'      => AppLibrary::currencyAmountFormat($specialPrice),
            'flat_discounted_price' => AppLibrary::flatAmountFormat($specialPrice),
            'convert_discounted_price' => AppLibrary::convertAmountFormat($specialPrice),

            'cover'    => $this->cover,
            'is_offer' => true,

            // Not the product's own flash-sale flag. On a campaign page that
            // badge would describe a different promotion than the one the
            // customer is looking at.
            'flash_sale' => false,

            // 0, not products.discount. The special price is already final —
            // the cart subtracts `discount` from the line total, so carrying
            // the retail percentage across would discount it a second time.
            'discount' => 0,

            'stock'                     => $this->stock ?? 0,
            'taxes'                     => ProductTaxResource::collection($this->taxes),
            // See SimpleProductResource: the cart needs this for product-wise shipping.
            'shipping'                  => [
                'shipping_type'                => $this->shipping_type,
                'shipping_cost'                => $this->shipping_cost,
                'is_product_quantity_multiply' => $this->is_product_quantity_multiply,
            ],
            'maximum_purchase_quantity' => $this->maximum_purchase_quantity,
            'rating_star'               => $this->rating_star,
            'rating_star_count'         => (int) $this->rating_star_count,
            'wishlist'                  => (bool) $this->wishlist,

            // Tags the cart line this card creates. Cart lines are keyed by
            // (product_id, variation_id, price_source), so the same product
            // added here and from the normal listing stays two lines at two
            // prices instead of collapsing into one at whichever price landed
            // first. Scoped per campaign id so two campaigns holding the same
            // product also stay apart.
            'price_source' => 'campaign:' . $this->pivot->campaign_id,
            'campaign_id'  => (int) $this->pivot->campaign_id,
        ];
    }
}
