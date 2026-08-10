<?php

namespace App\Http\Resources;

use App\Libraries\AppLibrary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'type'           => $this->type,
            'from_outlet_id' => $this->from_outlet_id,
            // Null on either side is the unassigned pool, which the UI labels
            // rather than leaving blank.
            'from_outlet'    => $this->fromOutlet?->name,
            'to_outlet_id'   => $this->to_outlet_id,
            'to_outlet'      => $this->toOutlet?->name,
            'date'           => $this->date,
            'converted_date' => AppLibrary::datetime($this->date),
            'reference_no'   => $this->reference_no,
            'note'           => $this->note,
            'creator'        => $this->creator?->name,
            'total_items'    => $this->stocks_count,
            'products'       => StockAdjustmentProductResource::collection($this->whenLoaded('stocks')),
        ];
    }
}
