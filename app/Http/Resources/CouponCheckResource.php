<?php

namespace App\Http\Resources;

use App\Libraries\AppLibrary;
use App\Support\OrderTotals;
use Illuminate\Http\Resources\Json\JsonResource;

class CouponCheckResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id'                => $this->id,
            'code'              => $this->code,
            'discount'          => $this->amount($request),
            "flat_discount"     => AppLibrary::flatAmountFormat($this->amount($request)),
            "convert_discount"  => AppLibrary::convertAmountFormat($this->amount($request)),
            "currency_discount" => AppLibrary::currencyAmountFormat($this->amount($request)),
        ];
    }

    // One formula for the quote and for the order: OrderTotals checks the
    // discount an order claims against this same figure.
    public function amount($request)
    {
        return OrderTotals::couponDiscount($this->resource, (float) $request->total);
    }
}
