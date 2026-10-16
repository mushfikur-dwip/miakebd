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
            // Never below zero while the "stock cannot be zero" switch is off:
            // the overdraft is carried separately, as a remark, because a
            // negative count tells a shopkeeper nothing.
            'stock'              => $this['stock'],
            // The figure behind it. The edit dialog adds onto this one, so a
            // branch at -1 given 1 unit reads 0 rather than claiming 1.
            'real_stock'         => $this['real_stock'] ?? $this['stock'],
            'billed_beyond'      => $this['billed_beyond'] ?? 0,
            // False when the product is set to Can Purchasable = No, i.e. the
            // storefront reports a fixed quantity and ignores this count.
            'stock_tracked'      => $this['stock_tracked'] ?? true,
            'outlet_stocks'      => $this['outlet_stocks'] ?? [],

        ];
    }
}
