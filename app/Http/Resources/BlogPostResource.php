<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-side representation: every editable field, including the SEO tab.
 * The public site uses FrontendBlogPostResource instead.
 */
class BlogPostResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'               => $this->id,
            'blog_category_id' => $this->blog_category_id,
            'category_name'    => $this->category?->name ?? '',
            'title'            => $this->title,
            'slug'             => $this->slug,
            'excerpt'          => $this->excerpt ?? '',
            'content'          => $this->content ?? '',
            'author_name'      => $this->author_name ?? '',
            'is_featured'      => (bool) $this->is_featured,
            // Ids for the editor's multiselect; names so the list can show them
            // without a second request.
            'tag_ids'          => $this->whenLoaded('tags', fn() => $this->tags->pluck('id')->values()),
            'tag_names'        => $this->whenLoaded('tags', fn() => $this->tags->pluck('name')->values()),
            'views'            => (int) $this->views,
            // Y-m-d\TH:i so it drops straight into <input type="datetime-local">
            // without the Vue side having to reformat it.
            'published_at'     => $this->published_at?->format('Y-m-d\TH:i') ?? '',
            'meta_title'       => $this->meta_title ?? '',
            'meta_description' => $this->meta_description ?? '',
            'meta_keywords'    => $this->meta_keywords ?? '',
            'canonical_url'    => $this->canonical_url ?? '',
            'robots'           => $this->robots ?? '',
            'status'           => $this->status,
            'cover'            => $this->cover,
            'url'              => $this->url,
            'reading_minutes'  => $this->reading_minutes,
        ];
    }
}
