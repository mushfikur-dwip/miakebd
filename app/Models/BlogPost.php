<?php

namespace App\Models;

use App\Enums\Status;
use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class BlogPost extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'blog_posts';

    protected $fillable = [
        'blog_category_id',
        'title',
        'slug',
        'excerpt',
        'content',
        'author_name',
        'created_by',
        'is_featured',
        'published_at',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'canonical_url',
        'robots',
        'status',
    ];

    protected $casts = [
        'id'               => 'integer',
        'blog_category_id' => 'integer',
        'title'            => 'string',
        'slug'             => 'string',
        'is_featured'      => 'boolean',
        'views'            => 'integer',
        'published_at'     => 'datetime',
        'status'           => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'blog_category_id', 'id');
    }

    /** Concerns this post addresses — acne, sunburn, tan. */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(BlogTag::class, 'blog_post_tag', 'blog_post_id', 'blog_tag_id');
    }

    /**
     * Everything the public site is allowed to show.
     *
     * Both conditions matter. `status` is the admin's on/off switch; a null or
     * future `published_at` is a scheduled post. Filtering on status alone
     * would publish a post the moment it was saved, which defeats the point of
     * having a separate publish date.
     */
    public function scopePublished(Builder $query): Builder
    {
        // Columns are table-qualified because this scope also runs inside
        // whereHas('posts', ...) on the tags many-to-many, where the query is
        // joined against blog_post_tag. Bare column names would be ambiguous
        // the moment that pivot gains a column of the same name.
        return $query->where('blog_posts.status', Status::ACTIVE)
            ->whereNotNull('blog_posts.published_at')
            ->where('blog_posts.published_at', '<=', now());
    }

    /**
     * Percent-encoded, because this URL is handed straight to og:image for a
     * shared post. A cover named with a space, an "&" or an em dash produced a
     * link preview with no picture - WhatsApp fetches og:image exactly as
     * written and does not repair it the way a browser would.
     */
    public function getCoverAttribute(): string
    {
        $url = $this->getFirstMediaUrl('blog-post-cover');

        return $url !== '' ? MediaUrl::encode(asset($url)) : '';
    }

    /**
     * Public URL. Built from config('app.url') rather than url()/route()
     * because this value becomes rel=canonical, the JSON-LD @id and the
     * sitemap entry — all three must be stable regardless of which host the
     * request arrived on. Same reasoning as CategoryMetaResolver::siteUrl().
     */
    public function getUrlAttribute(): string
    {
        return rtrim((string) config('app.url'), '/') . '/blog/' . rawurlencode((string) $this->slug);
    }

    /**
     * Excerpt for cards and meta description. Falls back to the opening of the
     * body so a post with no hand-written excerpt still gets a real summary
     * instead of an empty meta description.
     */
    public function getSummaryAttribute(): string
    {
        if (filled($this->excerpt)) {
            return trim((string) $this->excerpt);
        }

        $text = html_entity_decode(strip_tags((string) $this->content), ENT_QUOTES | ENT_HTML5);
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > 200 ? mb_substr($text, 0, 200) . '…' : $text;
    }

    public function getReadingMinutesAttribute(): int
    {
        // Split on whitespace rather than str_word_count(), which only counts
        // ASCII letters and returns 0 for an entire Bengali post.
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $this->content)));
        $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text));

        // 200 wpm is the usual reading estimate; the floor keeps a very short
        // post from rendering "0 min read".
        return max(1, (int) ceil($words / 200));
    }
}
