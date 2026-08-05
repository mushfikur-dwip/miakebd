<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BlogTagResource extends JsonResource
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
            'url'              => $this->url,
            'posts_count'      => $this->whenCounted('posts'),
            // Public listings pass a count constrained to published posts under
            // this alias; the admin list uses the raw posts_count above.
            'published_posts_count' => (int) ($this->published_posts_count ?? 0),
        ];
    }
}
