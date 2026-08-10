<?php

namespace App\Http\Resources;

use App\Models\ProductVariation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockAdjustmentProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'product_id'      => $this->product_id,
            'product_name'    => $this?->product?->name,
            'variation_id'    => $this->item_type === ProductVariation::class ? $this->item_id : 0,
            'variation_names' => $this->variation_names,
            'sku'             => $this->sku,
            'outlet_id'       => $this->outlet_id,
            // A transfer stores one negative and one positive row per item, so
            // the raw sign is what tells the two apart. The screen only ever
            // shows the moved amount.
            'quantity'        => abs($this->quantity),
        ];
    }
}
