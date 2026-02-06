<?php

namespace App\Http\Resources;

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
            'stock'             => $this->stock ?? 0,
            'taxes'             => ProductTaxResource::collection($this->taxes),
            'maximum_purchase_quantity' => $this->maximum_purchase_quantity,
            'rating_star'       => $this->rating_star,
            'rating_star_count' => (int) $this->rating_star_count,
            'wishlist'          => (bool)$this->wishlist,
        ];
    }
}
