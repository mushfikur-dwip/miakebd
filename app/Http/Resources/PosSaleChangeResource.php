<?php

namespace App\Http\Resources;

use App\Libraries\AppLibrary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One correction of a till sale, as its order page lists them. */
class PosSaleChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'action'           => $this->action,
            'amount'           => $this->amount,
            'amount_currency'  => AppLibrary::currencyAmountFormat($this->amount),
            'from_method'      => $this->from_method,
            'from_method_name' => $this->from_method ? trans('posPaymentMethod.' . $this->from_method) : null,
            'from_note'        => $this->from_note,
            'to_method'        => $this->to_method,
            'to_method_name'   => $this->to_method ? trans('posPaymentMethod.' . $this->to_method) : null,
            'to_note'          => $this->to_note,
            'reason'           => $this->reason,
            'by'               => $this->creator?->name,
            'at'               => AppLibrary::datetime($this->created_at),
        ];
    }
}
