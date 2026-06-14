<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StoreSalesReportOverviewResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'total_orders'    => $this['total_orders'],
            'total_earnings'  => $this['total_earnings'],
            'total_discounts' => $this['total_discounts'],
            'total_branches'  => $this['total_branches'],
        ];
    }
}
