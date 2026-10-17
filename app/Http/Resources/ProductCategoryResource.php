<?php

namespace App\Http\Resources;


use Illuminate\Http\Resources\Json\JsonResource;

class ProductCategoryResource extends JsonResource
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
            'id'              => $this->id,
            'name'            => $this->name,
            'slug'            => $this->slug,
            'description'     => $this->description === null ? '' : $this->description,
            'parent_category' => optional($this->parent_category)->name,
            'status'          => $this->status,
            'parent_id'       => $this->parent_id,
            'is_featured'     => $this->is_featured,
            'thumb'           => $this->thumb,
            'cover'           => $this->cover,
            // For the admin's edit dialog only - the shop's menus would carry
            // every category's intro text for nothing.
            $this->mergeWhen($request->is('api/admin/*'), fn () => [
                'seo_title'       => (string) $this->seo_title,
                'seo_description' => (string) $this->seo_description,
                'seo_content'     => (string) $this->seo_content,
            ]),
        ];
    }
}
