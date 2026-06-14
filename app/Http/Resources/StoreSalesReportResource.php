<?php

namespace App\Http\Resources;

use App\Libraries\AppLibrary;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreSalesReportResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                      => $this->id,
            'branch_name'             => $this?->outlet?->name ?? '',
            'order_serial_no'         => $this->order_serial_no,
            'order_datetime'          => AppLibrary::datetime($this->order_datetime),
            'customer_name'           => $this?->user?->name ?? '',
            'total_amount_price'      => AppLibrary::flatAmountFormat($this->total),
            'discount_amount_price'   => AppLibrary::flatAmountFormat($this->discount),
            'pos_payment_method'      => $this->pos_payment_method,
            'pos_payment_method_name' => trans("posPaymentMethod." . $this->pos_payment_method),
            'status'                  => $this->status,
            'status_name'             => trans('orderStatus.' . $this->status),
        ];
    }
}
