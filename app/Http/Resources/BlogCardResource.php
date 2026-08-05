<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A post as it appears in a grid card or sidebar list. Deliberately omits
 * `content` — the listing endpoint returns up to 12 of these, and shipping full
 * article bodies made the blog index payload larger than the homepage.
 */
class BlogCardResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'title'           => $this->title,
            'slug'            => $this->slug,
            'excerpt'         => $this->summary,
            'cover'           => $this->cover,
            // null, not an array of nulls: an uncategorised post returned
            // {name: null, slug: null}, which is truthy in JS, so the card
            // rendered an empty badge linking to /blog/category/undefined.
            'category'        => $this->category ? [
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ] : null,
            'author_name'     => $this->author_name ?? '',
            'published_at'    => $this->published_at?->toIso8601String(),
            'published_human' => $this->published_at?->translatedFormat('d M, Y'),
            'reading_minutes' => $this->reading_minutes,
            'views'           => (int) $this->views,
        ];
    }
}
