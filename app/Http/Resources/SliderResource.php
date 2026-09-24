<?php

namespace App\Http\Resources;


use App\Enums\SliderPosition;
use Illuminate\Http\Resources\Json\JsonResource;

class SliderResource extends JsonResource
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
            'id'          => $this->id,
            'title'       => $this->title,
            'description' => $this->description === null ? '' : $this->description,
            'status'      => $this->status,
            'image'       => $this->image,
            // Coalesced because this resource is read on every home page load
            // and the column is absent until the migration runs.
            'position'    => $this->position ?? SliderPosition::HERO,
            'tile'        => $this->tile,
            'link'        => $this->link
        ];
    }
}
