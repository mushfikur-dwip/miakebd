<?php

namespace App\Support;

use App\Enums\Status;
use App\Libraries\AppLibrary;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Server-side product metadata for /product/{slug}.
 *
 * WHY THIS EXISTS: when a product link is shared on WhatsApp or Facebook their
 * crawler fetches the URL and reads only the raw HTML — it never runs
 * JavaScript. Because the storefront is a Vue SPA, the product name, image and
 * price exist only after Vue boots, so without this the crawler sees the
 * generic site fallback and every preview looks identical.
 *
 * SAFETY: every lookup is wrapped. On any failure this returns null and the
 * page falls back to site-wide metadata rather than white-screening.
 *
 * Troubleshoot with:  php artisan tinker
 *                     >>> App\Support\ProductMetaResolver::debug('some-slug')
 */
class ProductMetaResolver
{
    /** URL segment that identifies a product page: /product/{slug} */
    private const URL_PREFIXES = ['product'];

    /**
     * Spatie MediaLibrary collection holding the product photos.
     *
     * A barcode image also lives in `media` for these products, with a LOWER
     * order_column, so any "first media row" lookup returns the barcode. The
     * collection name is the filter that prevents that.
     */
    private const MEDIA_COLLECTION = 'product';

    /**
     * Collections that must never become a social preview image, used by the
     * raw-table fallback below where no collection name is implied.
     */
    private const MEDIA_EXCLUDE = [
        // 'product-barcode' is the name ProductService actually registers.
        // Listing only 'barcode' meant whereNotIn never matched it, so a
        // product with a barcode but no photo published the barcode as its
        // WhatsApp preview — the exact failure this list exists to prevent.
        'product-barcode',
        'barcode', 'barcodes', 'qr', 'qrcode', 'document', 'documents',
        'invoice', 'attachment', 'attachments', 'file', 'files',
    ];

    /**
     * Conversion used for the preview image.
     *
     * 'cover' is 372x405. The originals here run to ~1.2 MB, which WhatsApp
     * frequently times out on, leaving the preview with no image at all.
     * Registered conversions on Product: thumb, cover, preview.
     */
    private const MEDIA_CONVERSION = 'cover';

    private const CACHE_MINUTES = 30;

    /** Stored in place of null so misses are cached instead of re-queried. */
    private const MISS = '__no_product__';

    /**
     * Metadata for the CURRENT request if it is a product URL, else null.
     *
     * @return array<string,mixed>|null
     */
    public static function resolve(): ?array
    {
        $segments = request()->segments();

        if (count($segments) < 2 || !in_array($segments[0], self::URL_PREFIXES, true)) {
            return null;
        }

        return self::forSlug($segments[1]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function forSlug(string $slug): ?array
    {
        // Reject anything implausible before touching the database.
        if (!preg_match('/^[A-Za-z0-9\-_.]{1,200}$/', $slug)) {
            return null;
        }

        try {
            $cached = Cache::remember(
                'suglow_product_meta:' . $slug,
                now()->addMinutes(self::CACHE_MINUTES),
                fn() => self::build($slug) ?? self::MISS
            );

            return $cached === self::MISS ? null : $cached;
        } catch (\Throwable $e) {
            // Metadata must never be able to break a page.
            Log::warning('ProductMetaResolver failed', [
                'slug' => $slug,
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return null;
        }
    }

    /** Call after editing a product so the preview refreshes immediately. */
    public static function forget(string $slug): void
    {
        Cache::forget('suglow_product_meta:' . $slug);
    }

    /**
     * @return array<string,mixed>
     */
    public static function debug(string $slug): array
    {
        $report = ['slug' => $slug];

        try {
            $product = Product::where('slug', $slug)->first();
            $report['product_found'] = $product !== null;

            if ($product === null) {
                $report['hint'] = "No product row where slug = '{$slug}'.";

                return $report;
            }

            $report['product_id'] = $product->id;
            $report['status'] = $product->status;
            $report['is_active'] = (int) $product->status === Status::ACTIVE;
            $report['seo_table_exists'] = Schema::hasTable('product_seos');
            $report['media_collection_count'] = $product->getMedia(self::MEDIA_COLLECTION)->count();
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
        $product = Product::query()
            ->with(['seo', 'media', 'category', 'brand', 'variations'])
            ->withSum('stockItems', 'quantity')
            ->where('slug', $slug)
            ->first();

        if ($product === null) {
            return null;
        }

        // status is App\Enums\Status: ACTIVE = 5, INACTIVE = 10.
        //
        // This is an explicit comparison rather than a generic "is it zero"
        // check. A generic check treats INACTIVE (10) as visible, which would
        // publish metadata — and social previews — for every product the shop
        // has deliberately taken down.
        if ((int) $product->status !== Status::ACTIVE) {
            return null;
        }

        $name = self::clean($product->name);

        if ($name === null || $name === '') {
            return null;
        }

        // ---- SEO record -------------------------------------------------
        $seoTitle = self::clean($product->seo?->title);

        // The imported description is three blocks separated by blank lines.
        // Only block 1 is a real meta description; blocks 2 and 3 are the AEO
        // answer and the GEO entity table, meant for visible page content, and
        // would read as noise in a WhatsApp preview.
        $seoDescription = self::firstBlock($product->seo?->description);

        // ---- Titles -----------------------------------------------------
        // Two lengths: <title> wants ~65 chars before Google truncates,
        // og:title can run longer because WhatsApp wraps it.
        $fullTitle = $seoTitle ?: ($name . ' — Price in Bangladesh | Suglow');

        // ---- Description -------------------------------------------------
        $description = $seoDescription ?: self::firstBlock($product->description);

        if ($description === null || $description === '') {
            $description = "Buy {$name} at Suglow. Authentic imported cosmetics with cash on delivery across Bangladesh.";
        }

        // ---- Price -------------------------------------------------------
        // Mirrors SeoSchema::product() so the social preview, the JSON-LD and
        // the price the customer sees in the SPA cannot disagree. A generic
        // "first numeric column" scan misses both the variation price and the
        // discount window, and would advertise the wrong number.
        $basePrice = count($product->variations) > 0
            ? $product->variation_price
            : $product->selling_price;

        $currentPrice = AppLibrary::isBetweenDate($product->offer_start_date, $product->offer_end_date)
            ? $basePrice - (($basePrice / 100) * $product->discount)
            : $basePrice;

        $price = is_numeric($currentPrice) && (float) $currentPrice > 0
            ? number_format((float) $currentPrice, 2, '.', '')
            : null;

        // Strike-through price, only when a discount is genuinely running.
        $comparePrice = null;
        if ($price !== null && (float) $basePrice > (float) $currentPrice) {
            $comparePrice = number_format((float) $basePrice, 2, '.', '');
        }

        // ---- Stock -------------------------------------------------------
        // Delegated to SeoSchema so the social preview, the JSON-LD and the
        // product page cannot disagree. There is no stock column on products —
        // quantities live in stock_items — so a generic column scan finds
        // nothing and reports InStock for the whole catalogue.
        $inStock = SeoSchema::isInStock($product);

        return [
            'name' => $name,
            'title' => self::limit($fullTitle, 65),
            'social_title' => self::limit($fullTitle, 95),
            'description' => self::limit($description, 200),
            'image' => self::resolveImage($product),
            // Config-derived, not route(): route() resolves against whatever
            // host made the request and this array is cached for 30 minutes,
            // so a health check on the bare IP would pin that host into the
            // canonical URL served to every visitor.
            'url' => rtrim((string) config('app.url'), '/') . '/product/' . rawurlencode($product->slug),
            'price' => $price,
            'compare_price' => $comparePrice,
            'currency' => 'BDT',
            'availability' => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'in_stock' => $inStock,
            'sku' => self::clean($product->sku),
            'brand' => self::clean($product->brand?->name),
            'category' => self::clean($product->category?->name),
            'category_slug' => $product->category?->slug,
        ];
    }

    /**
     * Absolute, publicly reachable image URL.
     *
     * WhatsApp and Facebook reject relative paths and will not follow a
     * redirect chain, so what comes back here has to be final and absolute.
     */
    private static function resolveImage(Product $product): string
    {
        $fallback = asset('images/required/theme-favicon-logo.png');

        // 1. The product collection, via Spatie. Calling getFirstMediaUrl()
        //    with no argument reads the "default" collection, which is empty
        //    here — that is how earlier versions ended up serving the barcode.
        try {
            $url = $product->getFirstMediaUrl(self::MEDIA_COLLECTION, self::MEDIA_CONVERSION);

            // A registered conversion that has not been generated yet returns
            // an empty string, so fall back to the original before giving up.
            if (!is_string($url) || trim($url) === '') {
                $url = $product->getFirstMediaUrl(self::MEDIA_COLLECTION);
            }

            if (is_string($url) && trim($url) !== '' && !self::looksLikeBarcode($url)) {
                return self::absolute($url);
            }
        } catch (\Throwable $e) {
            // Collection not registered — fall through to the raw table.
        }

        // 2. Raw media table, in case the Spatie helper is unavailable.
        try {
            if (Schema::hasTable('media')) {
                $row = DB::table('media')
                    ->where('model_id', $product->getKey())
                    ->where('model_type', Product::class)
                    ->whereNotIn('collection_name', self::MEDIA_EXCLUDE)
                    ->where('mime_type', 'like', 'image/%')
                    ->orderBy('order_column')
                    ->first();

                // The name guard runs on this path too — the collection filter
                // above only catches barcodes stored under a known collection.
                if ($row !== null && !empty($row->file_name) && !self::looksLikeBarcode($row->file_name)) {
                    // Spatie stores files at storage/{media_id}/{file_name}.
                    // Only spaces need encoding; parentheses are valid in a URL
                    // and Spatie itself does not encode them.
                    return self::absolute('storage/' . $row->id . '/' . str_replace(' ', '%20', $row->file_name));
                }
            }
        } catch (\Throwable $e) {
            // Media table shaped differently — use the fallback.
        }

        return $fallback;
    }

    /**
     * Guards against a barcode or QR image becoming the social preview — a
     * barcode in a WhatsApp preview reads as a broken link to a customer.
     */
    private static function looksLikeBarcode(string $url): bool
    {
        return (bool) preg_match('/(barcode|qr[-_]?code|\bqr\b)/i', $url);
    }

    private static function absolute(string $path): string
    {
        $path = trim($path);

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        if (Str::startsWith($path, '//')) {
            return 'https:' . $path;
        }

        return asset(ltrim($path, '/'));
    }

    /**
     * The imported description holds three blocks:
     *   1. the meta description        <- the only part that belongs in <meta>
     *   2. the AEO question and answer <- belongs in visible page content
     *   3. the GEO pipe-separated entity table
     *
     * They should be separated by blank lines, but the import collapsed some,
     * gluing block 2 onto block 1 so previews read "...Fast Dhaka delivery.
     * What is the price of...". Three fallbacks are applied in order.
     */
    private static function firstBlock(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        // Paragraph markup is a block separator too — the editor stores
        // </p><p> where the spreadsheet had a blank line.
        $text = preg_replace(
            '/<\/p>\s*<p>\s*(?:<br\s*\/?>|&nbsp;|\s)*\s*<\/p>\s*<p>/i',
            "</p>\n\n<p>",
            trim($value)
        );

        // 1. The intended separator: a blank line.
        $first = trim(preg_split('/\R\s*\R/', $text)[0] ?? '');

        // 2. Explicit markers, in case the blank lines were lost on import.
        //    "Q:" opens the AEO block, "Product |" opens the GEO entity table.
        $first = preg_split('/\s*(?:Q\s*:|\bProduct\s*\|)/iu', $first)[0];

        // 3. Still glued? The AEO block always opens with a question word
        //    starting a fresh sentence. Only applied past 175 characters so a
        //    legitimate short description containing "What" is untouched.
        if (mb_strlen($first) > 175) {
            $first = preg_split(
                '/(?<=[.!?])\s+(?=(?:What|Is|Are|Does|Do|How|Where|Which|Why|When|Can|Should)\b)/u',
                $first
            )[0];
        }

        return self::clean($first);
    }

    /**
     * Collapses whitespace and strips markup and control characters so the
     * value is safe and tidy inside an HTML attribute.
     */
    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Decode first, so "&amp;" becomes "&" and Blade re-escapes it once
        // rather than the page showing a double-escaped "&amp;amp;".
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim($value) ?: null;
    }

    /** Truncates on a word boundary, never mid-word. */
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
