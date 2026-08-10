<?php

namespace App\Http\Resources;


use App\Libraries\AppLibrary;
use Illuminate\Http\Resources\Json\JsonResource;

class StockResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */

    public function toArray($request)
    {
        return [

            'product_id'         => $this['product_id'],
            // 0 for a plain product. The edit dialog sends it back so the write
            // lands on the variation the row actually represents.
            'variation_id'       => $this['variation_id'] ?? 0,
            'product_name'       => $this['product_name'],
            'variation_names'    => $this['variation_names'],
            'sku'                => $this['sku'] ?? null,
            'status'             => $this['status'],
            'stock'              => $this['stock'],
            // False when the product is set to Can Purchasable = No, i.e. the
            // storefront reports a fixed quantity and ignores this count.
            'stock_tracked'      => $this['stock_tracked'] ?? true,
            'outlet_stocks'      => $this['outlet_stocks'] ?? [],

        ];
    }
}
