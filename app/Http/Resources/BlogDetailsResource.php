<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Single-post payload. Carries the SEO fields too, so the Vue side can set the
 * client-rendered head via useHead() to match what the server already wrote
 * into the raw HTML for crawlers.
 */
class BlogDetailsResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'title'           => $this->title,
            'slug'            => $this->slug,
            'excerpt'         => $this->summary,
            'content'         => $this->content,
            'cover'           => $this->cover,
            'category'        => $this->category ? [
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ] : null,
            // Concerns — acne, sunburn, tan. Rendered as chips under the
            // article and each one links to its own landing page.
            'tags'            => $this->tags->map(fn($tag) => [
                'name' => $tag->name,
                'slug' => $tag->slug,
            ])->values(),
            'author_name'     => $this->author_name ?? '',
            'published_at'    => $this->published_at?->toIso8601String(),
            'published_human' => $this->published_at?->translatedFormat('d M, Y'),
            'updated_at'      => $this->updated_at?->toIso8601String(),
            'reading_minutes' => $this->reading_minutes,
            'views'           => (int) $this->views,
            'url'             => $this->url,

            'meta_title'       => $this->meta_title ?: $this->title,
            'meta_description' => $this->meta_description ?: $this->summary,
            'meta_keywords'    => $this->meta_keywords ?? '',
            'canonical_url'    => $this->canonical_url ?: $this->url,
        ];
    }
}
