<?php

namespace App\Http\Resources;


use Illuminate\Http\Resources\Json\JsonResource;

class ProductBrandResource extends JsonResource
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
            'name'        => $this->name,
            'slug'        => $this->slug,
            // Coalesced because the column does not exist until the migration
            // runs, and the admin product form reads this on every load.
            'is_default'  => (bool) ($this->is_default ?? false),
            'description' => $this->description === null ? '' : $this->description,
            'status'      => $this->status,
            'thumb'       => $this->thumb,
            'cover'       => $this->cover
        ];
    }
}
