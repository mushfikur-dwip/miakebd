<?php

namespace App\Support;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Server-side metadata for /blog, /blog/{slug} and /blog/category/{slug}.
 *
 * WHY THIS EXISTS: the storefront is a Vue SPA. useHead() only writes meta
 * tags after JavaScript runs, and Google's first crawl pass — plus every
 * social and AI crawler — reads only the raw HTML the server returned. A blog
 * whose articles serve generic site-wide HTML to crawlers cannot rank for the
 * long-tail queries it was written for, which is the entire point of having
 * one. This class builds the real title, description, keywords, canonical,
 * social image and JSON-LD for each blog URL before the response leaves PHP.
 *
 * SAFETY: every lookup is wrapped. On failure the resolver returns null and
 * the page falls back to site-wide metadata rather than white-screening.
 *
 * Mirrors CategoryMetaResolver's contract so RootController can hand either
 * one's output straight to master.blade.php as $seo.
 */
class BlogMetaResolver
{
    private const CACHE_PREFIX = 'suglow_blog_meta:';
    private const CACHE_MINUTES = 30;
    private const MISS = '__no_blog__';

    /** Posts named in a category page's ItemList schema. */
    private const SAMPLE_POSTS = 8;

    private const PHONE = '01709786330';

    /**
     * Bumped by the services on every write. Cache keys embed it, so a single
     * increment invalidates every cached blog entry at once — far cheaper than
     * tracking and forgetting each slug individually, and it cannot leave a
     * stale entry behind when a post is renamed.
     */
    private const VERSION_KEY = 'suglow_blog_meta_version';

    public static function flush(): void
    {
        try {
            // forever(current + 1), NOT Cache::increment(): on the file and
            // database stores increment() is a no-op when the key does not
            // exist yet, so on a fresh install the version stayed at the
            // default 1 and publishing a post never invalidated anything.
            Cache::forever(self::VERSION_KEY, self::version() + 1);
        } catch (\Throwable $e) {
            // A cache driver that cannot increment (e.g. a cold `array` store
            // in a queue worker) must not break saving a post.
            Log::warning('BlogMetaResolver flush failed: ' . $e->getMessage());
        }
    }

    private static function version(): int
    {
        try {
            return (int) Cache::get(self::VERSION_KEY, 1);
        } catch (\Throwable $e) {
            return 1;
        }
    }

    private static function siteUrl(): string
    {
        // Config-derived, never url()/route(): these values become rel=canonical
        // and the JSON-LD @id, and they get cached. A single health check on
        // the bare IP would otherwise pin that host into the canonical of every
        // blog page until the cache expired.
        return rtrim((string) config('app.url'), '/');
    }

    public static function indexUrl(): string
    {
        return self::siteUrl() . '/blog';
    }

    public static function postUrl(string $slug): string
    {
        return self::siteUrl() . '/blog/' . rawurlencode($slug);
    }

    public static function categoryUrl(string $slug): string
    {
        return self::siteUrl() . '/blog/category/' . rawurlencode($slug);
    }

    private static function brandImage(): string
    {
        return asset('images/required/og-suglow.jpg');
    }

    // ---------------------------------------------------------------- index

    /**
     * @return array<string,mixed>
     */
    public static function forIndex(): array
    {
        $url = self::indexUrl();

        return [
            'title'       => 'Beauty Tips, Skincare Guides & Product Reviews — Suglow Blog',
            'description' => self::limit(
                'Skincare routines, makeup how-tos and honest product reviews from Suglow — '
                . "Bangladesh's authentic cosmetics store. Written for Bangladeshi skin, weather and budgets.",
                158
            ),
            'keywords'    => 'beauty tips bangladesh, skin care tips bangla, skincare routine bangladesh, '
                . 'makeup tips bd, cosmetics review bangladesh, beauty blog bangladesh, '
                . 'rupchorcha, ত্বকের যত্ন, suglow blog',
            'canonical'   => $url,
            'image'       => self::brandImage(),
            'type'        => 'website',
            'robots'      => 'index, follow, max-image-preview:large, max-snippet:-1',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function indexStructuredData(): array
    {
        $siteUrl = self::siteUrl();
        $url = self::indexUrl();

        return [
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type'       => 'Blog',
                    '@id'         => $url . '#blog',
                    'name'        => 'Suglow Blog',
                    'description' => 'Beauty tips, skincare guides and product reviews for Bangladesh.',
                    'url'         => $url,
                    'inLanguage'  => ['bn', 'en'],
                    'publisher'   => ['@id' => $siteUrl . '/#organization'],
                ],
                self::breadcrumb([
                    ['Home', $siteUrl . '/'],
                    ['Blog', $url],
                ], $url),
            ],
        ];
    }

    // ----------------------------------------------------------------- post

    /**
     * @return array<string,mixed>|null
     */
    public static function forPost(string $slug): ?array
    {
        return self::cached('post:' . $slug, fn() => self::buildPost($slug));
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function buildPost(string $slug): ?array
    {
        $post = BlogPost::with(['media', 'category'])->published()->where('slug', $slug)->first();

        if (!$post) {
            return null;
        }

        $title = self::clean($post->meta_title) ?: self::clean($post->title);

        if (blank($title)) {
            return null;
        }

        $description = self::clean($post->meta_description) ?: self::clean($post->summary);
        $description = self::limit($description ?: 'Read this article on the Suglow blog.', 158);

        $cover = $post->cover ?: self::brandImage();

        return [
            'title'       => self::limit($title, 65),
            'description' => $description,
            'keywords'    => self::clean($post->meta_keywords) ?: self::postKeywords($post),
            // A hand-set canonical_url wins so a syndicated piece can point at
            // its original home; otherwise the post self-canonicalises to the
            // STORED slug, not the requested spelling.
            'canonical'   => self::clean($post->canonical_url) ?: self::postUrl($post->slug),
            'image'       => $cover,
            'type'        => 'article',
            'robots'      => self::clean($post->robots) ?: 'index, follow, max-image-preview:large, max-snippet:-1',
            'article'     => [
                'published_time' => $post->published_at?->toIso8601String(),
                'modified_time'  => $post->updated_at?->toIso8601String(),
                'section'        => $post->category?->name,
                'author'         => $post->author_name ?: 'Suglow',
            ],
            // Consumed by the <noscript> block so crawlers that do not run JS
            // still get the real article text, not an empty shell.
            'body'        => $post->content,
            'name'        => $post->title,
            'slug'        => $post->slug,
        ];
    }

    /**
     * BlogPosting plus a breadcrumb. BlogPosting rather than Article because
     * Google treats it as the more specific type for a blog, and it is what
     * earns the article rich result.
     *
     * @return array<string,mixed>|null
     */
    public static function postStructuredData(string $slug): ?array
    {
        try {
            $post = BlogPost::with(['media', 'category'])->published()->where('slug', $slug)->first();

            if (!$post) {
                return null;
            }

            $siteUrl = self::siteUrl();
            $url = self::postUrl($post->slug);
            $cover = $post->cover ?: self::brandImage();

            $article = [
                '@type'            => 'BlogPosting',
                '@id'              => $url . '#article',
                'headline'         => self::limit((string) $post->title, 110),
                'description'      => self::limit(self::clean($post->summary) ?: '', 158),
                'url'              => $url,
                // mainEntityOfPage is what tells Google this markup describes
                // THIS page rather than an article merely referenced by it.
                'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
                'image'            => [$cover],
                'datePublished'    => $post->published_at?->toIso8601String(),
                'dateModified'     => $post->updated_at?->toIso8601String(),
                'author'           => [
                    '@type' => 'Person',
                    'name'  => $post->author_name ?: 'Suglow',
                ],
                'publisher'        => ['@id' => $siteUrl . '/#organization'],
                'inLanguage'       => self::looksBengali($post->title . ' ' . $post->content) ? 'bn' : 'en',
                'wordCount'        => self::wordCount($post->content),
            ];

            if ($post->category) {
                $article['articleSection'] = $post->category->name;
            }

            $trail = [
                ['Home', $siteUrl . '/'],
                ['Blog', self::indexUrl()],
            ];

            if ($post->category) {
                $trail[] = [$post->category->name, self::categoryUrl($post->category->slug)];
            }

            $trail[] = [$post->title, $url];

            return [
                '@context' => 'https://schema.org',
                '@graph'   => [
                    array_filter($article, fn($value) => $value !== null && $value !== ''),
                    self::breadcrumb($trail, $url),
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning('BlogMetaResolver::postStructuredData failed', ['slug' => $slug, 'error' => $e->getMessage()]);

            return null;
        }
    }

    // ------------------------------------------------------------- category

    /**
     * @return array<string,mixed>|null
     */
    public static function forCategory(string $slug): ?array
    {
        return self::cached('category:' . $slug, fn() => self::buildCategory($slug));
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function buildCategory(string $slug): ?array
    {
        $category = BlogCategory::with('media')->active()->where('slug', $slug)->first();

        if (!$category) {
            return null;
        }

        $name = self::clean($category->name);

        if (blank($name)) {
            return null;
        }

        $count = BlogPost::published()->where('blog_category_id', $category->id)->count();

        $posts = BlogPost::published()
            ->where('blog_category_id', $category->id)
            ->orderByDesc('published_at')
            ->limit(self::SAMPLE_POSTS)
            ->get(['id', 'title', 'slug'])
            ->map(fn($post) => ['name' => (string) $post->title, 'url' => self::postUrl($post->slug)])
            ->all();

        $title = self::clean($category->meta_title)
            ?: self::limit("{$name} — Tips, Guides & Reviews | Suglow Blog", 65);

        $description = self::clean($category->meta_description) ?: self::clean($category->description);

        if (blank($description)) {
            $countPhrase = $count > 0 ? "{$count} articles on" : 'Articles on';
            $description = "{$countPhrase} {$name} from the Suglow blog — practical beauty and skincare "
                . 'advice for Bangladesh. Call ' . self::PHONE . '.';
        }

        return [
            'title'       => $title,
            'description' => self::limit($description, 158),
            'keywords'    => self::clean($category->meta_keywords) ?: self::categoryKeywords($name),
            'canonical'   => self::categoryUrl($category->slug),
            'image'       => $category->cover ?: self::brandImage(),
            'type'        => 'website',
            'robots'      => 'index, follow, max-image-preview:large, max-snippet:-1',
            'name'        => $name,
            'slug'        => $category->slug,
            'count'       => $count,
            'posts'       => $posts,
        ];
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    public static function categoryStructuredData(array $meta): array
    {
        $siteUrl = self::siteUrl();
        $url = $meta['canonical'];

        $graph = [
            [
                '@type'       => 'CollectionPage',
                '@id'         => $url . '#collection',
                'name'        => $meta['name'],
                'description' => $meta['description'],
                'url'         => $url,
                'isPartOf'    => ['@id' => self::indexUrl() . '#blog'],
            ],
            self::breadcrumb([
                ['Home', $siteUrl . '/'],
                ['Blog', self::indexUrl()],
                [$meta['name'], $url],
            ], $url),
        ];

        // Only when there are real posts. An empty ItemList is worse than none
        // — Google reads it as a broken signal.
        if (!empty($meta['posts'])) {
            $graph[] = [
                '@type'           => 'ItemList',
                '@id'             => $url . '#posts',
                'name'            => $meta['name'],
                'numberOfItems'   => $meta['count'],
                'itemListElement' => array_values(array_map(
                    fn($post, $index) => [
                        '@type'    => 'ListItem',
                        'position' => $index + 1,
                        'name'     => $post['name'],
                        'url'      => $post['url'],
                    ],
                    $meta['posts'],
                    array_keys($meta['posts'])
                )),
            ];
        }

        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    // ------------------------------------------------------------------ tag

    public static function tagUrl(string $slug): string
    {
        return self::siteUrl() . '/blog/tag/' . rawurlencode($slug);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function forTag(string $slug): ?array
    {
        return self::cached('tag:' . $slug, fn() => self::buildTag($slug));
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function buildTag(string $slug): ?array
    {
        $tag = BlogTag::active()->where('slug', $slug)->first();

        if (!$tag) {
            return null;
        }

        $name = self::clean($tag->name);

        if (blank($name)) {
            return null;
        }

        $count = BlogPost::published()
            ->whereHas('tags', fn($query) => $query->where('blog_tags.id', $tag->id))
            ->count();

        // A concern with nothing published behind it is a thin page. 404 it
        // rather than let Google index an empty result set.
        if ($count === 0) {
            return null;
        }

        $posts = BlogPost::published()
            ->whereHas('tags', fn($query) => $query->where('blog_tags.id', $tag->id))
            ->orderByDesc('published_at')
            ->limit(self::SAMPLE_POSTS)
            ->get(['id', 'title', 'slug'])
            ->map(fn($post) => ['name' => (string) $post->title, 'url' => self::postUrl($post->slug)])
            ->all();

        $title = self::clean($tag->meta_title)
            ?: self::limit("{$name} — Causes, Treatment & Product Guide | Suglow", 65);

        $description = self::clean($tag->meta_description) ?: self::clean($tag->description);

        if (blank($description)) {
            $description = "{$count} articles on {$name} from the Suglow blog — what causes it, what "
                . 'actually works, and which products to use in Bangladesh. Call ' . self::PHONE . '.';
        }

        return [
            'title'       => $title,
            'description' => self::limit($description, 158),
            'keywords'    => self::clean($tag->meta_keywords) ?: self::tagKeywords($name),
            'canonical'   => self::tagUrl($tag->slug),
            'image'       => self::brandImage(),
            'type'        => 'website',
            'robots'      => 'index, follow, max-image-preview:large, max-snippet:-1',
            'name'        => $name,
            'slug'        => $tag->slug,
            'count'       => $count,
            'posts'       => $posts,
        ];
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    public static function tagStructuredData(array $meta): array
    {
        $siteUrl = self::siteUrl();
        $url = $meta['canonical'];

        $graph = [
            [
                '@type'       => 'CollectionPage',
                '@id'         => $url . '#collection',
                'name'        => $meta['name'],
                'description' => $meta['description'],
                'url'         => $url,
                'isPartOf'    => ['@id' => self::indexUrl() . '#blog'],
                // about → the concern itself. This is the signal that ties the
                // page to a real-world topic rather than a bag of keywords.
                'about'       => ['@type' => 'Thing', 'name' => $meta['name']],
            ],
            self::breadcrumb([
                ['Home', $siteUrl . '/'],
                ['Blog', self::indexUrl()],
                [$meta['name'], $url],
            ], $url),
        ];

        if (!empty($meta['posts'])) {
            $graph[] = [
                '@type'           => 'ItemList',
                '@id'             => $url . '#posts',
                'name'            => $meta['name'],
                'numberOfItems'   => $meta['count'],
                'itemListElement' => array_values(array_map(
                    fn($post, $index) => [
                        '@type'    => 'ListItem',
                        'position' => $index + 1,
                        'name'     => $post['name'],
                        'url'      => $post['url'],
                    ],
                    $meta['posts'],
                    array_keys($meta['posts'])
                )),
            ];
        }

        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    private static function tagKeywords(string $name): string
    {
        $lower = mb_strtolower($name);

        return implode(', ', array_unique([
            "{$lower} treatment bangladesh",
            "{$lower} solution bd",
            "{$lower} er chikitsha",
            "how to remove {$lower}",
            "best product for {$lower} in bangladesh",
            "{$lower} bangla tips",
            'skin care bangladesh',
            'suglow blog',
        ]));
    }

    // -------------------------------------------------------------- helpers

    /**
     * @param  array<int,array{0:string,1:string}>  $trail
     * @return array<string,mixed>
     */
    private static function breadcrumb(array $trail, string $url): array
    {
        return [
            '@type'           => 'BreadcrumbList',
            '@id'             => $url . '#breadcrumb',
            'itemListElement' => array_values(array_map(
                fn($crumb, $index) => [
                    '@type'    => 'ListItem',
                    'position' => $index + 1,
                    'name'     => $crumb[0],
                    'item'     => $crumb[1],
                ],
                $trail,
                array_keys($trail)
            )),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function cached(string $key, callable $builder): ?array
    {
        try {
            $cacheKey = self::CACHE_PREFIX . self::version() . ':' . $key;
            $cached = Cache::get($cacheKey);

            if ($cached !== null) {
                return $cached === self::MISS ? null : $cached;
            }

            $built = $builder();

            // Misses expire after a minute. RootController turns a null into a
            // 404, so caching one for the full window would keep a just-
            // published post 404ing long after it went live.
            Cache::put(
                $cacheKey,
                $built ?? self::MISS,
                now()->addMinutes($built === null ? 1 : self::CACHE_MINUTES)
            );

            return $built;
        } catch (\Throwable $e) {
            Log::warning('BlogMetaResolver failed', ['key' => $key, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private static function postKeywords(BlogPost $post): string
    {
        $terms = [];

        if ($post->category?->name) {
            $lower = mb_strtolower($post->category->name);
            $terms[] = $lower . ' bangladesh';
            $terms[] = $lower . ' tips bangla';
        }

        $terms[] = 'beauty tips bangladesh';
        $terms[] = 'skin care bangladesh';
        $terms[] = 'suglow blog';

        return implode(', ', array_unique(array_filter($terms)));
    }

    private static function categoryKeywords(string $name): string
    {
        $lower = mb_strtolower($name);

        return implode(', ', array_unique([
            "{$lower} bangladesh",
            "{$lower} tips",
            "{$lower} bangla",
            "{$lower} bd",
            'beauty tips bangladesh',
            'skin care tips bangla',
            'suglow blog',
        ]));
    }

    /** Bengali content should declare inLanguage "bn", not "en". */
    private static function looksBengali(string $text): bool
    {
        return (bool) preg_match('/\p{Bengali}/u', $text);
    }

    private static function wordCount(?string $html): int
    {
        // Whitespace split, not str_word_count() — that counts ASCII letters
        // only and reports 0 for an entire Bengali article.
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $html)));

        return $text === '' ? 0 : count(preg_split('/\s+/u', $text));
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim($value) ?: null;
    }

    private static function limit(string $value, int $length): string
    {
        $value = trim($value);

        if (mb_strlen($value) <= $length) {
            return $value;
        }

        $cut = mb_substr($value, 0, $length);
        $lastSpace = mb_strrpos($cut, ' ');

        if ($lastSpace !== false && $lastSpace > $length * 0.6) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \t\n\r\0\x0B-–—,.|") . '…';
    }

    /**
     * @return array<string,mixed>
     */
    public static function debug(string $slug): array
    {
        $report = ['slug' => $slug];

        try {
            $post = BlogPost::where('slug', $slug)->first();
            $report['post_found'] = $post !== null;

            if ($post) {
                $report['status'] = $post->status;
                $report['published_at'] = (string) $post->published_at;
                $report['is_public'] = BlogPost::published()->where('slug', $slug)->exists();
            }

            $report['meta'] = self::buildPost($slug);
        } catch (\Throwable $e) {
            $report['exception'] = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
        }

        return $report;
    }
}
