<?php

namespace App\Models;

use App\Enums\Status;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A reader concern — acne, sunburn, tan. See the migration for why this is
 * separate from BlogCategory.
 */
class BlogTag extends Model
{
    protected $table = 'blog_tags';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'priority',
        'status',
    ];

    protected $casts = [
        'id'       => 'integer',
        'name'     => 'string',
        'slug'     => 'string',
        'priority' => 'integer',
        'status'   => 'integer',
    ];

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(BlogPost::class, 'blog_post_tag', 'blog_tag_id', 'blog_post_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', Status::ACTIVE);
    }

    public function getUrlAttribute(): string
    {
        return rtrim((string) config('app.url'), '/') . '/blog/tag/' . rawurlencode((string) $this->slug);
    }
}
