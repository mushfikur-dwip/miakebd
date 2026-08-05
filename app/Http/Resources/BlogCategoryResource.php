<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BlogCategoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'slug'             => $this->slug,
            'description'      => $this->description ?? '',
            'meta_title'       => $this->meta_title ?? '',
            'meta_description' => $this->meta_description ?? '',
            'meta_keywords'    => $this->meta_keywords ?? '',
            'priority'         => $this->priority,
            'status'           => $this->status,
            'cover'            => $this->cover,
            // withCount('posts') is only applied on the listing query, so this
            // stays absent rather than firing a query per row on show().
            'posts_count'      => $this->whenCounted('posts'),
        ];
    }
}
