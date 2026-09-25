<?php

namespace App\Http\Resources;


use Illuminate\Http\Resources\Json\JsonResource;

class SimpleUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        // The till's customer box searches whatever it is shown, so the number
        // has to travel with the name - a cashier who only has the phone could
        // not find anybody before this.
        //
        // `phone` is stored without its leading zero (guest checkout strips it,
        // and the calling code lives in country_code), so the local form is
        // rebuilt here: that is what is printed on a receipt and what somebody
        // reads off their phone at the counter.
        $phone = trim((string) $this->phone);

        return [
            "id"           => $this->id,
            "name"         => $this->name,
            "phone"        => $phone,
            "country_code" => $this->country_code,
            "local_phone"  => $phone === '' ? '' : (str_starts_with($phone, '0') ? $phone : '0' . $phone),
        ];
    }
}
