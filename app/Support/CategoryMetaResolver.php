<?php

namespace App\Support;

use App\Enums\Status;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Server-side category metadata for /product-category/{slug}.
 *
 * WHY THIS EXISTS: the nine category pages are the highest-intent commercial
 * queries this shop has ("sunscreen price in bangladesh"), but as a Vue SPA
 * they served the same generic HTML to Google as every other route — nine
 * pages competing as one.
 *
 * SAFETY: every lookup is wrapped. On failure this returns null and the page
 * falls back to site-wide metadata rather than white-screening.
 *
 * Troubleshoot with:  php artisan tinker
 *                     >>> App\Support\CategoryMetaResolver::debug('sunscreen')
 */
class CategoryMetaResolver
{
    /**
     * Clean category URL. The old query-string form (/product?category=slug)
     * is still recognised so redirected and bookmarked traffic resolves, but
     * everything this class emits canonicalises to the path form.
     */
    private const URL_PREFIX = 'product-category';

    /** Legacy listing path plus its query parameter. */
    private const LEGACY_PATH = 'product';
    private const LEGACY_QUERY_KEY = 'category';

    private const PHONE = '01709786330';
    private const SOURCES = 'Malaysia, Thailand & Indonesia';

    /** How many real product names to expose in the ItemList schema. */
    private const SAMPLE_PRODUCTS = 8;

    private const CACHE_MINUTES = 30;

    private const MISS = '__no_category__';

    /**
     * Extra keyword terms, added only when a category name matches. The
     * generic block is always included, so a category added later in the admin
     * panel still gets a complete keyword set.
     *
     * @var array<string,string>
     */
    private const KEYWORD_HINTS = [
        'skin' => 'skin care products bd, face care bangladesh, skin treatment bd',
        'sun' => 'sunblock bangladesh, spf cream bd, sun protection bangladesh',
        'hair' => 'hair oil bangladesh, shampoo bd, hair treatment bangladesh',
        'baby' => 'baby lotion bangladesh, baby products bd, baby skin care bangladesh',
        'fragrance' => 'perfume bangladesh, body spray bd, deodorant bangladesh',
        'moistur' => 'moisturizing cream bangladesh, face lotion bd, hydrating cream bangladesh',
        'men' => 'mens grooming bangladesh, men face wash bd, mens skin care bangladesh',
        'personal' => 'personal hygiene bangladesh, body care bd, daily care bangladesh',
        'accessor' => 'beauty accessories bangladesh, makeup tools bd',
    ];

    /**
     * Metadata for the CURRENT request if it is a category URL, else null.
     *
     * @return array<string,mixed>|null
     */
    public static function resolve(): ?array
    {
        $segments = request()->segments();

        // Clean form: /product-category/{slug}
        if (count($segments) >= 2 && $segments[0] === self::URL_PREFIX) {
            return self::forSlug($segments[1]);
        }

        // Legacy form: /product?category={slug}
        if (($segments[0] ?? null) === self::LEGACY_PATH) {
            $slug = request()->query(self::LEGACY_QUERY_KEY);

            if (is_string($slug) && $slug !== '') {
                return self::forSlug($slug);
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function forSlug(string $slug): ?array
    {
        if (!preg_match('/^[A-Za-z0-9\-_.]{1,200}$/', $slug)) {
            return null;
        }

        try {
            $key = 'suglow_category_meta:' . $slug;
            $cached = Cache::get($key);

            if ($cached !== null) {
                return $cached === self::MISS ? null : $cached;
            }

            $built = self::build($slug);

            // Misses are cached for one minute, hits for the full window.
            // RootController turns a null into a 404, so caching a miss for
            // half an hour meant a category that was briefly inactive — or one
            // an admin had just re-activated — kept 404ing long after the cause
            // was gone.
            Cache::put(
                $key,
                $built ?? self::MISS,
                now()->addMinutes($built === null ? 1 : self::CACHE_MINUTES)
            );

            return $built;
        } catch (\Throwable $e) {
            Log::warning('CategoryMetaResolver failed', [
                'slug' => $slug,
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return null;
        }
    }

    public static function forget(string $slug): void
    {
        Cache::forget('suglow_category_meta:' . $slug);
    }

    /**
     * Site root taken from config, NOT from the incoming request.
     *
     * url()/route() resolve against whatever host the current request used, and
     * these values get cached for 30 minutes and emitted as rel=canonical. One
     * health check on the bare IP, or an internal http:// call, would otherwise
     * pin that host into the canonical for every visitor until the cache
     * expired — and it already produced two different hosts inside a single
     * JSON-LD graph.
     */
    private static function siteUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** Canonical public URL for a category slug. */
    public static function url(string $slug): string
    {
        return self::siteUrl() . '/' . self::URL_PREFIX . '/' . rawurlencode($slug);
    }

    /**
     * @return array<string,mixed>
     */
    public static function debug(string $slug): array
    {
        $report = ['slug' => $slug];

        try {
            $category = ProductCategory::where('slug', $slug)->first();
            $report['category_found'] = $category !== null;

            if ($category === null) {
                $report['hint'] = "No product_categories row where slug = '{$slug}'.";

                return $report;
            }

            $report['category_id'] = $category->id;
            $report['status'] = $category->status;
            $report['is_active'] = (int) $category->status === Status::ACTIVE;
            $report['canonical'] = self::url($slug);
            $report['result'] = self::build($slug);
        } catch (\Throwable $e) {
            $report['exception'] = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
        }

        return $report;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function build(string $slug): ?array
    {
        // NOTE: no whereNull('deleted_at') anywhere in this class.
        // product_categories has no deleted_at column and any query adding one
        // throws SQLSTATE[42S22] Unknown column.
        $category = ProductCategory::where('slug', $slug)->first();

        if ($category === null) {
            return null;
        }

        // status is App\Enums\Status: ACTIVE = 5, INACTIVE = 10. Compared
        // explicitly — a generic "is it zero" check treats INACTIVE as visible.
        if ((int) $category->status !== Status::ACTIVE) {
            return null;
        }

        $name = self::clean($category->name);

        if ($name === null || $name === '') {
            return null;
        }

        // Category names are inconsistently cased in the database — "hair
        // care", "moisturizer", "Men skin care". Left alone they land in the
        // page title exactly like that. Title-case anything without an
        // acronym, so deliberate capitalisation such as "SPF" survives.
        if (!preg_match('/[A-Z]{2,}/', $name)) {
            $name = Str::title($name);
        }

        // The listing this page renders matches on the category AND all of its
        // descendants (ProductService uses descendantsAndSelf). Counting only
        // the category itself made every parent advertise a wrong product count
        // and emit an empty ItemList — which structuredData() then drops, so a
        // parent category lost the one signal this class exists to produce.
        $categoryIds = self::descendantIds($category);

        $count = self::productCount($categoryIds);
        $sample = self::sampleProducts($categoryIds);

        // A category-specific title is what turns nine identical pages into
        // nine separate ranking opportunities. Under 65 chars so Google does
        // not truncate it.
        $title = self::limit("{$name} Price in Bangladesh | Buy Online — Suglow", 65);

        $ownDescription = self::clean($category->description);

        if ($ownDescription !== null && mb_strlen($ownDescription) > 60) {
            $description = self::limit($ownDescription, 158);
        } else {
            // Kept short enough that the phone number survives the 158-char
            // limit — it is the call to action, and the longer wording pushed
            // it past the cut, leaving descriptions ending in "Call…".
            $countPhrase = $count > 0 ? "{$count} authentic" : 'authentic';
            $description = self::limit(
                "Shop {$countPhrase} {$name} products at Suglow Bangladesh. "
                . 'Imported from ' . self::SOURCES . '. '
                . 'Cash on delivery. Call ' . self::PHONE . '.',
                158
            );
        }

        // The STORED slug, not the requested one. MySQL compares case
        // -insensitively, so /product-category/Skin-Care6 resolves — and
        // canonicalising back to the requested spelling would let every case
        // variant self-canonicalise into its own indexable duplicate, which is
        // the splitting this whole migration exists to stop.
        $slug = $category->slug;

        return [
            'name' => $name,
            'slug' => $slug,
            'title' => $title,
            'description' => $description,
            'keywords' => self::buildKeywords($name),
            'image' => self::resolveImage($category),
            // The canonical is the clean path. It must NOT be built with
            // url()->current(): that strips the query string, so on the legacy
            // /product?category=x form it produced
            // <link rel="canonical" href="https://suglow.com/product">, telling
            // Google every category page is a duplicate of the plain listing.
            // All nine were cancelling each other out.
            'url' => self::url($slug),
            'count' => $count,
            'products' => $sample,
        ];
    }

    /**
     * Structured data for a category page: CollectionPage describing the page,
     * BreadcrumbList for the results trail, and an ItemList of real products.
     *
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    public static function structuredData(array $meta): array
    {
        $siteUrl = self::siteUrl();

        $graph = [
            [
                '@type' => 'CollectionPage',
                '@id' => $meta['url'] . '#collection',
                'name' => $meta['name'],
                'description' => $meta['description'],
                'url' => $meta['url'],
                'isPartOf' => ['@id' => $siteUrl . '/#website'],
            ],
            [
                '@type' => 'BreadcrumbList',
                '@id' => $meta['url'] . '#breadcrumb',
                'itemListElement' => [
                    [
                        '@type' => 'ListItem',
                        'position' => 1,
                        'name' => 'Home',
                        'item' => $siteUrl . '/',
                    ],
                    [
                        '@type' => 'ListItem',
                        'position' => 2,
                        'name' => $meta['name'],
                        'item' => $meta['url'],
                    ],
                ],
            ],
        ];

        // Only emit an ItemList when there are real products to list. An empty
        // list is worse than none — Google treats it as a broken signal.
        if (!empty($meta['products'])) {
            $graph[] = [
                '@type' => 'ItemList',
                '@id' => $meta['url'] . '#products',
                'name' => $meta['name'],
                'numberOfItems' => $meta['count'],
                'itemListElement' => array_values(array_map(
                    fn($product, $index) => [
                        '@type' => 'ListItem',
                        'position' => $index + 1,
                        'name' => $product['name'],
                        'url' => $product['url'],
                    ],
                    $meta['products'],
                    array_keys($meta['products'])
                )),
            ];
        }

        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    /**
     * The generic keyword block is always present; hints only add extra terms.
     */
    private static function buildKeywords(string $name): string
    {
        $lower = mb_strtolower($name);

        $terms = [
            "{$lower} bangladesh",
            "{$lower} price in bangladesh",
            "buy {$lower} online bd",
            "authentic {$lower} bangladesh",
            "{$lower} bd",
            'cosmetics bangladesh',
            'suglow',
        ];

        foreach (self::KEYWORD_HINTS as $needle => $extra) {
            if (str_contains($lower, $needle)) {
                $terms[] = $extra;
            }
        }

        return implode(', ', array_unique($terms));
    }

    /**
     * The category plus every category beneath it, matching what the listing
     * actually queries. Falls back to the category alone if the recursive
     * relation is unavailable.
     *
     * @return array<int,int>
     */
    private static function descendantIds(ProductCategory $category): array
    {
        try {
            if (method_exists($category, 'descendantsAndSelf')) {
                $ids = $category->descendantsAndSelf()->pluck('id')->all();

                if (!empty($ids)) {
                    return array_map('intval', $ids);
                }
            }
        } catch (\Throwable $e) {
            // Recursive relation not usable — fall through.
        }

        return [(int) $category->id];
    }

    /**
     * Live count of active products in the category and its descendants.
     *
     * @param  array<int,int>  $categoryIds
     */
    private static function productCount(array $categoryIds): int
    {
        try {
            return Product::whereIn('product_category_id', $categoryIds)
                ->where('status', Status::ACTIVE)
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Real product names for the ItemList schema and the crawler fallback.
     * Real names on the page are a far stronger signal than marketing copy.
     *
     * @param  array<int,int>  $categoryIds
     * @return array<int,array<string,string>>
     */
    private static function sampleProducts(array $categoryIds): array
    {
        try {
            return Product::query()
                ->select(['id', 'name', 'slug'])
                ->whereIn('product_category_id', $categoryIds)
                ->where('status', Status::ACTIVE)
                ->whereNotNull('slug')
                ->where('slug', '<>', '')
                ->orderByDesc('id')
                ->limit(self::SAMPLE_PRODUCTS)
                ->get()
                ->map(fn($product) => [
                    'name' => (string) $product->name,
                    // Config-derived for the same reason as siteUrl(): route()
                    // resolves against the requesting host and this is cached.
                    'url' => self::siteUrl() . '/product/' . rawurlencode($product->slug),
                ])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function resolveImage(ProductCategory $category): string
    {
        try {
            // ProductCategory exposes a `cover` accessor that already falls
            // back to a default asset, so this never returns empty.
            $cover = $category->cover;

            if (is_string($cover) && trim($cover) !== '') {
                return Str::startsWith($cover, ['http://', 'https://'])
                    ? $cover
                    : asset(ltrim($cover, '/'));
            }
        } catch (\Throwable $e) {
            // Media collection not registered — use the site fallback.
        }

        return asset('images/required/theme-favicon-logo.png');
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
}
