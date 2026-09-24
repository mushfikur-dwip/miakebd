<?php

namespace App\Http\Resources;

use App\Libraries\AppLibrary;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductTaxResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        // name/code/tax_rate added: the cart computes tax from these, and a
        // product added from a listing (this resource) carried none, so its
        // tax came out as zero while the same product added from its own page
        // (SimpleTaxResource) was taxed. The server charges the real rate now,
        // so both paths must show it.
        return [
            "id"         => $this->id,
            "product_id" => $this->product_id,
            "tax_id"     => $this->tax_id,
            "name"       => $this->tax?->name,
            "code"       => $this->tax?->code,
            "tax_rate"   => $this->tax ? AppLibrary::flatAmountFormat($this->tax->tax_rate) : 0,
        ];
    }
}
