<?php

namespace App\Models;

use App\Enums\Status;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class BlogCategory extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'blog_categories';

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

    public function posts(): HasMany
    {
        return $this->hasMany(BlogPost::class, 'blog_category_id', 'id');
    }

    /** Only categories that should appear in the public nav. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', Status::ACTIVE);
    }

    public function getCoverAttribute(): string
    {
        $url = $this->getFirstMediaUrl('blog-category-cover');

        return $url !== '' ? asset($url) : '';
    }
}
