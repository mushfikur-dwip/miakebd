<?php

namespace App\Support;

use App\Enums\Status;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Server-side brand metadata for /brand/{slug}.
 *
 * WHY THIS EXISTS: in Bangladesh a large share of cosmetics searches name the
 * brand - "cerave price in bangladesh", "the ordinary bd". The only brand view
 * the shop had was /product?brand=6, which canonicalises to /product, so to
 * Google every brand was the same page and none of them could rank. This gives
 * each brand its own indexable URL with its own title, description, product
 * list and structured data, in the raw HTML where crawlers read it.
 *
 * Mirrors CategoryMetaResolver: cached, config-derived URLs, and every lookup
 * wrapped so a failure falls back to site-wide metadata instead of an error.
 */
class BrandMetaResolver
{
    private const URL_PREFIX = 'brand';

    private const PHONE = '01709786330';

    /** Products listed in the ItemList and as plain links for crawlers. */
    private const SAMPLE_PRODUCTS = 48;

    private const CACHE_MINUTES = 30;

    private const MISS = '__no_brand__';

    /** Versioned: a page cached before the intro and questions existed has no `content`. */
    private const CACHE_KEY = 'suglow_brand_meta:v2:';

    public static function url(string $slug): string
    {
        return rtrim((string) config('app.url'), '/') . '/' . self::URL_PREFIX . '/' . rawurlencode($slug);
    }

    /**
     * @return array<string,mixed>|null null for an unknown, inactive or placeholder brand
     */
    public static function forSlug(string $slug): ?array
    {
        if (!preg_match('/^[A-Za-z0-9\-_.]{1,200}$/', $slug)) {
            return null;
        }

        try {
            $key = self::CACHE_KEY . $slug;
            $cached = Cache::get($key);

            if ($cached !== null) {
                return $cached === self::MISS ? null : $cached;
            }

            $built = self::build($slug);

            // A miss is cached for a minute only, so a brand an admin has just
            // activated does not keep 404ing for half an hour.
            Cache::put($key, $built ?? self::MISS, now()->addMinutes($built === null ? 1 : self::CACHE_MINUTES));

            return $built;
        } catch (\Throwable $e) {
            Log::warning('BrandMetaResolver failed', ['slug' => $slug, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Every brand worth a page - active, real, with at least one product on
     * the website - for the sitemap and the crawler links on /product.
     *
     * @return array<int,array{name: string, url: string, slug: string}>
     */
    public static function all(): array
    {
        try {
            // Cached like the category and brand pages: /product renders this
            // list on every visit, and it changes only when brands do.
            return Cache::remember('suglow_brand_list', now()->addMinutes(self::CACHE_MINUTES), fn () => self::buildAll());
        } catch (\Throwable $e) {
            Log::warning('BrandMetaResolver::all failed: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * No try/catch here on purpose: a failure must reach all(), which returns
     * an empty list WITHOUT caching it. Caught here, one brief database error
     * would be cached and every brand link would vanish for half an hour.
     *
     * @return array<int,array{name: string, url: string, slug: string}>
     */
    private static function buildAll(): array
    {
        return ProductBrand::query()
            ->where('status', Status::ACTIVE)
            ->storefront()
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->whereHas('products', fn ($query) => $query->where('status', Status::ACTIVE)->storefront())
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'updated_at'])
            ->map(fn ($brand) => [
                'name'       => SeoSchema::cleanName($brand->name),
                'slug'       => $brand->slug,
                'url'        => self::url($brand->slug),
                'updated_at' => $brand->updated_at,
            ])
            ->filter(fn ($brand) => $brand['name'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function build(string $slug): ?array
    {
        $brand = ProductBrand::where('slug', $slug)->first();

        if ($brand === null || (int) $brand->status !== Status::ACTIVE || (bool) ($brand->is_default ?? false)) {
            return null;
        }

        $name = SeoSchema::cleanName($brand->name);

        if ($name === '') {
            return null;
        }

        // The stored slug, not the requested spelling: MySQL matches slugs
        // case-insensitively, and each case variant must not canonicalise to
        // itself as a separate page.
        $slug = $brand->slug;

        $products = Product::query()
            ->where('product_brand_id', $brand->id)
            ->where('status', Status::ACTIVE)
            ->storefront()
            ->whereNotNull('slug')
            ->where('slug', '<>', '');

        $count = (clone $products)->count();

        $sample = (clone $products)
            ->with('variations')
            ->select(['id', 'name', 'slug', 'selling_price', 'variation_price', 'discount', 'offer_start_date', 'offer_end_date'])
            ->orderByDesc('id')
            ->limit(self::SAMPLE_PRODUCTS)
            ->get()
            ->map(fn ($product) => [
                'name'  => SeoSchema::cleanName($product->name),
                'url'   => rtrim((string) config('app.url'), '/') . '/product/' . rawurlencode($product->slug),
                'price' => SeoSchema::pricing($product)['current'],
            ])
            ->all();

        [$min, $max, $cheapest, $dearest] = CollectionSeo::prices($products);

        // "Price in Bangladesh" is how shoppers here phrase a brand search, and
        // what the category titles already use. The admin's own title wins.
        $title = filled($brand->seo_title)
            ? SeoSchema::cleanName($brand->seo_title)
            : SeoSchema::limit("{$name} Price in Bangladesh — 100% Authentic {$name} | Suglow", 70);

        // The admin's own description first, then the brand's own words, then
        // one built from live data with the price range leading.
        $ownDescription = SeoSchema::plainText($brand->description);
        $description = match (true) {
            filled($brand->seo_description) => SeoSchema::limit(SeoSchema::plainText($brand->seo_description), 158),
            mb_strlen($ownDescription) > 60 => SeoSchema::limit($ownDescription, 158),
            $count > 0                      => CollectionSeo::description($name, $count, CollectionSeo::range($min, $max)),
            default                         => SeoSchema::limit("Shop authentic {$name} products at Suglow Bangladesh. Original stock, cash on delivery nationwide. Call " . self::PHONE . '.', 158),
        };

        $content = CollectionSeo::content([
            'kind'         => CollectionSeo::BRAND,
            'name'         => $name,
            'count'        => $count,
            'min'          => $min,
            'max'          => $max,
            'cheapest'     => $cheapest,
            'dearest'      => $dearest,
            'best_sellers' => CollectionSeo::bestSellers($products),
            'related'      => self::categoriesOf($products),
            'own_text'     => $brand->description,
            'admin_text'   => $brand->seo_content,
        ]);

        return [
            'name'        => $name,
            'slug'        => $slug,
            'title'       => $title,
            'description' => $description,
            'keywords'    => self::keywords($name),
            'image'       => self::image($brand),
            'url'         => self::url($slug),
            'count'       => $count,
            'price_range' => CollectionSeo::range($min, $max),
            'products'    => $sample,
            'content'     => $content,
            // A brand with nothing on sale still answers, so a link or a tile
            // never 404s - but it is kept out of the index as a thin page.
            'robots'      => $count > 0 ? 'index, follow, max-image-preview:large' : 'noindex, follow',
        ];
    }

    public static function forget(?string $slug): void
    {
        if (filled($slug)) {
            Cache::forget(self::CACHE_KEY . $slug);
        }
        Cache::forget('suglow_brand_list');
    }

    /**
     * The category pages a brand's products sit in, busiest first - the
     * "Shop CeraVe by category" links, so a brand page passes shoppers and
     * crawlers on to the category pages and back.
     *
     * @return array<int,array{name: string, url: string, count: int}>
     */
    private static function categoriesOf($products): array
    {
        $counts = (clone $products)
            ->whereNotNull('product_category_id')
            ->selectRaw('product_category_id, COUNT(*) as aggregate')
            ->groupBy('product_category_id')
            ->pluck('aggregate', 'product_category_id');

        if ($counts->isEmpty()) {
            return [];
        }

        return ProductCategory::query()
            ->whereIn('id', $counts->keys())
            ->where('status', Status::ACTIVE)
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->where('slug', '<>', CategoryMetaResolver::ALL_PRODUCT_SLUG)
            ->get(['id', 'name', 'slug'])
            ->map(fn ($category) => [
                'name'  => CategoryMetaResolver::displayName($category->name),
                'url'   => CategoryMetaResolver::url($category->slug),
                'count' => (int) $counts->get($category->id),
            ])
            ->filter(fn ($category) => $category['name'] !== '')
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * CollectionPage + BreadcrumbList + Brand + ItemList, like a category page.
     *
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    public static function structuredData(array $meta): array
    {
        $siteUrl = rtrim((string) config('app.url'), '/');

        $collection = [
            '@type'       => 'CollectionPage',
            '@id'         => $meta['url'] . '#collection',
            'name'        => $meta['name'] . ' products',
            'description' => $meta['description'],
            'url'         => $meta['url'],
            'isPartOf'    => ['@id' => $siteUrl . '/#website'],
            'about'       => ['@id' => $meta['url'] . '#brand'],
            'breadcrumb'  => ['@id' => $meta['url'] . '#breadcrumb'],
        ];

        if (!empty($meta['products'])) {
            $collection['mainEntity'] = ['@id' => $meta['url'] . '#products'];
        }

        // What the brand is, in the brand's own words when the admin wrote
        // some, so an answer engine can say more than its name.
        $brand = [
            '@type'       => 'Brand',
            '@id'         => $meta['url'] . '#brand',
            'name'        => $meta['name'],
            'url'         => $meta['url'],
            'description' => $meta['content']['intro'][0] ?? $meta['description'],
        ];

        if (!empty($meta['image'])) {
            $brand['logo'] = $meta['image'];
        }

        $graph = [
            $collection,
            $brand,
            [
                '@type'           => 'BreadcrumbList',
                '@id'             => $meta['url'] . '#breadcrumb',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $siteUrl . '/'],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'All products', 'item' => $siteUrl . '/product'],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $meta['name'], 'item' => $meta['url']],
                ],
            ],
        ];

        if (!empty($meta['products'])) {
            $graph[] = [
                '@type'           => 'ItemList',
                '@id'             => $meta['url'] . '#products',
                'name'            => $meta['name'] . ' products',
                'numberOfItems'   => $meta['count'],
                'itemListElement' => array_values(array_map(
                    fn ($product, $index) => [
                        '@type'    => 'ListItem',
                        'position' => $index + 1,
                        'name'     => $product['name'],
                        'url'      => $product['url'],
                    ],
                    $meta['products'],
                    array_keys($meta['products'])
                )),
            ];
        }

        // The same questions the page shows, word for word.
        if ($faq = CollectionSeo::faqNode($meta['url'], $meta['content']['faqs'] ?? [])) {
            $graph[] = $faq;
        }

        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    private static function keywords(string $name): string
    {
        $lower = mb_strtolower($name);

        return implode(', ', [
            "{$lower} price in bangladesh",
            "{$lower} bangladesh",
            "{$lower} bd",
            "original {$lower} bangladesh",
            "buy {$lower} online bd",
            'suglow',
        ]);
    }

    private static function image(ProductBrand $brand): ?string
    {
        try {
            $cover = $brand->cover;

            if (is_string($cover) && trim($cover) !== '' && !str_contains($cover, 'images/default')) {
                return Str::startsWith($cover, ['http://', 'https://']) ? $cover : asset(ltrim($cover, '/'));
            }
        } catch (\Throwable $e) {
            // No logo uploaded - the page falls back to the site card.
        }

        return null;
    }
}
