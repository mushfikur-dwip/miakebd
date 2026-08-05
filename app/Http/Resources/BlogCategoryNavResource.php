<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Category as shown in the public nav bar and the sidebar "Categories" list.
 * `posts_count` is the published count, supplied by the controller's
 * withCount() constraint — an unconstrained count would advertise drafts.
 */
class BlogCategoryNavResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'description' => $this->description ?? '',
            'cover'       => $this->cover,
            'posts_count' => (int) ($this->published_posts_count ?? 0),
        ];
    }
}
