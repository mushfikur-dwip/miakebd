<?php

namespace App\Http\Resources;

use App\Libraries\AppLibrary;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreSalesReportBranchSummaryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'outlet_id'                    => $this->outlet_id,
            'branch_name'                  => $this?->outlet?->name ?? '',
            'total_orders'                 => (int) $this->total_orders,
            'total_sales'                  => AppLibrary::flatAmountFormat($this->total_sales),
            'total_sales_currency'         => AppLibrary::currencyAmountFormat($this->total_sales),
            'total_discounts'              => AppLibrary::flatAmountFormat($this->total_discounts),
            'total_discounts_currency'     => AppLibrary::currencyAmountFormat($this->total_discounts),
        ];
    }
}
