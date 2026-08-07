<?php

namespace App\Http\Resources;

use App\Libraries\AppLibrary;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignProductAdminResource extends JsonResource
{
    /**
     * One row of the admin's campaign product table.
     *
     * Deliberately carries NO retail price. The special price is entered by
     * hand from scratch, and showing `selling_price` next to the input is what
     * turns "type the campaign price" into "discount the listed price" — the
     * exact behaviour the page-scoped design rules out. PromotionProductResource
     * does emit the listing price; this is that pattern with the price stripped.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function toArray($request): array
    {
        return [
            'id'                       => $this->id,
            'campaign_id'              => $this->campaign_id,
            'campaign_product_id'      => $this->product_id,
            'campaign_name'            => optional($this->campaign)->name,
            'campaign_product_name'    => optional($this->product)->name,
            'campaign_product_cover'   => optional($this->product)->cover,
            'campaign_product_sku'     => optional($this->product)->sku,
            'campaign_product_status'  => optional($this->product)->status,

            'special_price'          => AppLibrary::currencyAmountFormat($this->special_price),
            'flat_special_price'     => AppLibrary::flatAmountFormat($this->special_price),
        ];
    }
}
