<?php

namespace App\Http\Resources;


use App\Libraries\AppLibrary;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
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
            "id"           => $this->id,
            "name"         => $this->name,
            "username"     => $this->username,
            "email"        => $this->email,
            "phone"        => $this->phone === null ? '' : $this->phone,
            "status"       => $this->status,
            "role_id"      => optional($this->roles[0])->id,
            "role"         => optional($this->roles[0])->name,
            "image"        => $this->image,
            "country_code" => $this->country_code,
            // POS sales this employee was picked as "Sale By" for. Loaded by
            // the list and show queries; zero wherever they were not loaded.
            "sales_count"           => (int) ($this->sales_count ?? 0),
            "sales_amount"          => AppLibrary::flatAmountFormat($this->sales_amount ?? 0),
            "sales_currency_amount" => AppLibrary::currencyAmountFormat($this->sales_amount ?? 0),
        ];
    }
}
