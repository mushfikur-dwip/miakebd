<?php

namespace App\Support;

use App\Enums\Activity;
use App\Enums\Ask;
use App\Libraries\AppLibrary;
use App\Models\Product;

class SeoSchema
{
    /**
     * The product's price as the customer sees it, and its regular price when
     * an offer is running. One place, because the JSON-LD, the social preview,
     * the catalogue feed and the product page must never disagree on a price -
     * Google and Meta both disapprove items whose markup contradicts the page.
     *
     * @return array{current: float, regular: float, on_sale: bool, sale_ends: ?string, sale_starts: ?string}
     */
    public static function pricing(Product $product): array
    {
        $regular = (float) (count($product->variations) > 0 ? $product->variation_price : $product->selling_price);
        $onSale  = AppLibrary::isBetweenDate($product->offer_start_date, $product->offer_end_date)
            && (float) $product->discount > 0;
        $current = $onSale ? $regular - (($regular / 100) * (float) $product->discount) : $regular;

        return [
            'current'     => round($current, 2),
            'regular'     => round($regular, 2),
            'on_sale'     => $onSale && $current < $regular,
            'sale_starts' => $onSale ? date('c', strtotime((string) $product->offer_start_date)) : null,
            'sale_ends'   => $onSale ? date('c', strtotime((string) $product->offer_end_date)) : null,
        ];
    }

    /**
     * A name as it should be shown to a search engine: no control characters
     * (a category here is stored as "\x1DSkin Care"), no doubled spaces.
     */
    public static function cleanName(?string $value): string
    {
        $value = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * The brand worth naming - never the placeholder brand that products
     * without one are filed under.
     */
    public static function brandName(Product $product): ?string
    {
        $brand = $product->brand;

        if (!$brand || (bool) ($brand->is_default ?? false)) {
            return null;
        }

        return self::cleanName($brand->name) ?: null;
    }

    /**
     * A real description for a product nobody has written one for yet.
     *
     * Products added after the SEO import have no product_seos row, and some
     * have no description either, so their only "description" was their own
     * name repeated - which is what Google showed under the link, and all an
     * AI assistant had to go on. This says
     * what a shopper searching for the product wants to know: that it is
     * genuine, what it costs, who makes it and how it arrives.
     */
    public static function fallbackDescription(Product $product): string
    {
        $name     = self::cleanName($product->name);
        $brand    = self::brandName($product);
        $category = self::cleanName($product->category?->name);
        $price    = self::pricing($product)['current'];

        $what = trim(($brand ? "100% authentic {$brand}" : '100% authentic') . ($category ? ' ' . mb_strtolower($category) : ''));

        $parts = ["Buy {$name} in Bangladesh"];
        if ($price > 0) {
            $parts[0] .= ' at ৳' . number_format($price, 0);
        }
        // Kept near Google's ~160-character snippet: the shop's name is
        // already in the title, so it is not repeated here.
        $parts[] = $what;
        $parts[] = 'Cash on delivery across Bangladesh, delivered in 1-3 days';

        return implode('. ', $parts) . '.';
    }

    /**
     * Whether a stored description is real text rather than the product's
     * name pasted back in.
     */
    public static function hasRealDescription(Product $product): bool
    {
        $description = self::plainText($product->seo?->description ?: $product->description);
        $name        = self::cleanName($product->name);

        return $description !== ''
            && mb_strtolower(self::cleanName($description)) !== mb_strtolower($name)
            && mb_strlen($description) >= 40;
    }

    /** The description to publish: the written one, or the generated one. */
    public static function description(Product $product): string
    {
        return self::hasRealDescription($product)
            ? self::plainText($product->seo?->description ?: $product->description)
            : self::fallbackDescription($product);
    }

    public static function product(Product $product): array
    {
        $pricing = self::pricing($product);
        $currentPrice = $pricing['current'];
        $inStock = self::isInStock($product);
        // Config-derived for the same reason as the canonical in
        // RootController: this URL becomes the schema @id and Offer.url, and
        // must not vary with the host the request happened to use.
        $siteUrl = rtrim((string) config('app.url'), '/');
        $url = $siteUrl . '/product/' . rawurlencode($product->slug);

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            '@id' => $url.'#product',
            // Google rejects a Merchant listing name over 150 characters
            // ("Invalid string length in field name"). Ten products here run to
            // 190. Capped for the markup only — the page and the database keep
            // the full name.
            'name' => self::limit(self::cleanName($product->name), 150),
            // Google caps this at 5,000 characters.
            'description' => self::limit(self::description($product), 5000),
            'url' => $url,
            'image' => array_values(array_filter($product->previews ?: [$product->cover])),
            'sku' => $product->sku,
            'category' => self::cleanName($product->category?->name) ?: null,
            'offers' => array_filter([
                '@type' => 'Offer',
                'url' => $url,
                'priceCurrency' => 'BDT',
                'price' => number_format((float) $currentPrice, 2, '.', ''),
                // Only when the price really does expire - an offer's end.
                // Google warns when it is missing, but an invented date would
                // tell it the regular price stops being valid on that day.
                'priceValidUntil' => $pricing['on_sale'] ? substr((string) $pricing['sale_ends'], 0, 10) : null,
                // The regular price, shown struck through in Google's results
                // while an offer runs.
                'priceSpecification' => $pricing['on_sale'] ? [
                    '@type' => 'UnitPriceSpecification',
                    'priceType' => 'https://schema.org/StrikethroughPrice',
                    'price' => number_format($pricing['regular'], 2, '.', ''),
                    'priceCurrency' => 'BDT',
                ] : null,
                'itemCondition' => 'https://schema.org/NewCondition',
                'availability' => $inStock
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
                // The same entity the site-wide graph declares, so Google and
                // AI answers attribute every offer to one known retailer.
                'seller' => ['@id' => $siteUrl . '/#organization'],
                'hasMerchantReturnPolicy' => self::returnPolicy(),
                'shippingDetails' => self::shippingDetails(),
            ]),
        ];

        // Global identifier. Only a genuine, check-digit-valid manufacturer
        // barcode — see gtinFor() for why internally generated codes are
        // deliberately excluded.
        if ($gtin = self::gtinFor($product)) {
            $schema += $gtin;
        }

        if ($brand = self::brandName($product)) {
            $schema['brand'] = ['@type' => 'Brand', 'name' => $brand];
        }

        if ((int) $product->rating_star_count > 0 && (float) $product->rating_star > 0) {
            $schema['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => round((float) $product->rating_star / (int) $product->rating_star_count, 2),
                'reviewCount' => (int) $product->rating_star_count,
            ];
        }

        if ($reviews = self::reviews($product)) {
            $schema['review'] = $reviews;
        }

        return array_filter($schema, fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Individual reviews, and ONLY real ones.
     *
     * Search Console reports `review` as missing because the store genuinely has
     * none yet. That is the correct state to be in: marking up reviews that were
     * not written by customers breaches Google's structured data policy and
     * risks a manual action against the whole site. This emits nothing until a
     * customer actually writes one, then starts working on its own.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function reviews(Product $product): array
    {
        if (!$product->relationLoaded('reviews')) {
            return [];
        }

        return $product->reviews
            ->filter(fn ($review) => filled($review->review) && (int) $review->star > 0)
            ->sortByDesc('created_at')
            // Five is plenty for the rich result; the page itself lists them all.
            ->take(5)
            ->map(fn ($review) => array_filter([
                '@type' => 'Review',
                'reviewRating' => [
                    '@type' => 'Rating',
                    'ratingValue' => (int) $review->star,
                    'bestRating' => 5,
                    'worstRating' => 1,
                ],
                'author' => [
                    '@type' => 'Person',
                    // Reviewer name is required; fall back rather than emit an
                    // empty author, which Google flags as invalid.
                    'name' => $review->user?->name ?: 'Verified buyer',
                ],
                'reviewBody' => self::plainText($review->review),
                'datePublished' => $review->created_at?->toDateString(),
            ]))
            ->values()
            ->all();
    }

    /**
     * Return policy, as confirmed by the store: 7 days, free returns.
     *
     * @return array<string,mixed>
     */
    private static function returnPolicy(): array
    {
        return [
            '@type' => 'MerchantReturnPolicy',
            'applicableCountry' => 'BD',
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays' => 7,
            'returnMethod' => 'https://schema.org/ReturnByMail',
            'returnFees' => 'https://schema.org/FreeReturn',
        ];
    }

    /**
     * Delivery: flat rate nationwide, 0-1 day handling, 1-3 days transit.
     *
     * The rate is read from the shipping_setup settings rather than hardcoded,
     * so changing it in the admin panel updates what Google is told.
     *
     * @return array<string,mixed>
     */
    private static function shippingDetails(): array
    {
        $rate = 130.0;

        try {
            $configured = \Settings::group('shipping_setup')->get('shipping_setup_flat_rate_wise_cost');

            if (is_numeric($configured)) {
                $rate = (float) $configured;
            }
        } catch (\Throwable $e) {
            // Settings unavailable (console context, fresh install) — the
            // default above still produces valid markup.
        }

        return [
            '@type' => 'OfferShippingDetails',
            'shippingRate' => [
                '@type' => 'MonetaryAmount',
                'value' => number_format($rate, 2, '.', ''),
                'currency' => 'BDT',
            ],
            'shippingDestination' => [
                '@type' => 'DefinedRegion',
                'addressCountry' => 'BD',
            ],
            'deliveryTime' => [
                '@type' => 'ShippingDeliveryTime',
                'handlingTime' => [
                    '@type' => 'QuantitativeValue',
                    'minValue' => 0,
                    'maxValue' => 1,
                    'unitCode' => 'DAY',
                ],
                'transitTime' => [
                    '@type' => 'QuantitativeValue',
                    'minValue' => 1,
                    'maxValue' => 3,
                    'unitCode' => 'DAY',
                ],
            ],
        ];
    }

    /**
     * The product's GTIN, if its SKU is genuinely one.
     *
     * Two guards, both deliberate:
     *
     * 1. The check digit must validate. A malformed GTIN is worse than none —
     *    Google Merchant Center verifies them and disapproves items that fail.
     *
     * 2. GS1 reserves prefixes 02 and 20-29 for restricted circulation, i.e.
     *    codes a shop generates for its own shelves. `barcodes:backfill` uses
     *    that range for products with no manufacturer barcode, so they can be
     *    printed and scanned in-store. Those are NOT globally unique and must
     *    never be published as a gtin — doing so would claim another company's
     *    identifier.
     *
     * @return array<string,string>|null
     */
    public static function gtinFor(Product $product): ?array
    {
        $code = preg_replace('/\D/', '', (string) $product->sku);

        if (!self::isValidGtin($code)) {
            return null;
        }

        // Restricted-circulation range — internal only, never advertised.
        if (str_starts_with($code, '02') || preg_match('/^2[0-9]/', $code)) {
            return null;
        }

        return match (strlen($code)) {
            8 => ['gtin8' => $code],
            12 => ['gtin12' => $code],
            13 => ['gtin13' => $code],
            14 => ['gtin14' => $code],
            default => null,
        };
    }

    /**
     * GTIN-8/12/13/14 with a valid mod-10 check digit.
     *
     * Public so the backfill command can reuse exactly the same rule the schema
     * applies — two copies would inevitably drift.
     */
    public static function isValidGtin(?string $code): bool
    {
        $code = preg_replace('/\D/', '', (string) $code);

        if (!in_array(strlen($code), [8, 12, 13, 14], true)) {
            return false;
        }

        return self::gtinCheckDigit(substr($code, 0, -1)) === (int) substr($code, -1);
    }

    /**
     * Mod-10 check digit for a GTIN body (the code without its final digit).
     *
     * Weighting runs right-to-left from the body's last character: 3, 1, 3, 1…
     * which is what makes the same routine correct for all four GTIN lengths.
     */
    public static function gtinCheckDigit(string $body): int
    {
        $digits = array_reverse(str_split($body));
        $sum = 0;

        foreach ($digits as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 3 : 1);
        }

        return (10 - ($sum % 10)) % 10;
    }

    /**
     * Trim to a length on a word boundary. Mirrors CategoryMetaResolver::limit().
     */
    public static function limit(string $value, int $length): string
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

        return rtrim($cut, " \t\n\r\0\x0B-–—,.|");
    }

    /**
     * The full product-page graph: the Product entity plus a BreadcrumbList.
     *
     * Kept separate from product() because RootController reads price facts
     * straight out of product()'s top level; wrapping that output in a @graph
     * there would break the og/product:* tag extraction.
     *
     * @return array<string,mixed>
     */
    public static function productPage(Product $product): array
    {
        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                self::product($product),
                self::breadcrumb($product),
            ],
        ];
    }

    /**
     * Home > Category > Product. The category link is the clean
     * /product-category/{slug} path — the same URL the category route
     * canonicalises to — never the legacy query-string form.
     *
     * @return array<string,mixed>
     */
    private static function breadcrumb(Product $product): array
    {
        $siteUrl = rtrim((string) config('app.url'), '/');
        $url = $siteUrl . '/product/' . rawurlencode($product->slug);

        $items = [
            [
                '@type' => 'ListItem',
                'position' => 1,
                'name' => 'Home',
                'item' => $siteUrl . '/',
            ],
        ];

        $category = $product->category;
        $position = 2;

        if ($category && !empty($category->name) && !empty($category->slug)) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => 2,
                'name' => (string) $category->name,
                'item' => $siteUrl . '/product-category/' . rawurlencode((string) $category->slug),
            ];
            $position = 3;
        }

        $items[] = [
            '@type' => 'ListItem',
            'position' => $position,
            'name' => (string) $product->name,
            'item' => $url,
        ];

        return [
            '@type' => 'BreadcrumbList',
            '@id' => $url . '#breadcrumb',
            'itemListElement' => $items,
        ];
    }

    /**
     * Stock status exactly as the customer sees it.
     *
     * This mirrors the `stock` expression in SimpleProductDetailsResource,
     * which is what the product page renders. The previous version inverted two
     * of the three branches, so the structured data contradicted the page:
     *
     *   can_purchasable = NO   page showed "In stock (100)", schema said OutOfStock
     *   show_stock_out = ENABLE (the column default)
     *                          page showed "Stock out",      schema said InStock
     *
     * Google flags that as a mismatched-availability error and Merchant Center
     * disapproves the item, so the two must be derived from one expression.
     */
    public static function isInStock(Product $product): bool
    {
        if ($product->show_stock_out != Activity::DISABLE) {
            return false;
        }

        // A non-purchasable product is displayed with a synthetic quantity
        // (NON_PURCHASE_QUANTITY) rather than as sold out.
        if ($product->can_purchasable == Ask::NO) {
            return (int) env('NON_PURCHASE_QUANTITY') > 0;
        }

        return (int) $product->stock_items_sum_quantity > 0;
    }

    /**
     * Every block of a description as plain paragraphs - the meta
     * description, the question-and-answer block and the product facts the
     * SEO import wrote. plainText() keeps only the first, which is right for a
     * <meta> tag; a catalogue feed or a crawler reading the page wants all of
     * it, since that is exactly what an AI assistant quotes from.
     *
     * @return array<int,string>
     */
    public static function paragraphs(?string $value): array
    {
        $html = preg_replace('/<\s*(br|\/p|\/li|\/h[1-6]|\/div|\/tr)\s*\/?>/i', "\n", (string) $value);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', ' ', $text);

        return array_values(array_filter(array_map(
            fn ($line) => trim(preg_replace('/[ \t\x{A0}]+/u', ' ', $line)),
            preg_split('/\R/u', $text)
        ), fn ($line) => $line !== ''));
    }

    public static function plainText(?string $value): string
    {
        $normalized = preg_replace(
            '/<\/p>\s*<p>\s*(?:<br\s*\/?>|&nbsp;|\s)*\s*<\/p>\s*<p>/i',
            "</p>\n\n<p>",
            trim((string) $value)
        );
        $firstBlock = preg_split('/\R\s*\R/', $normalized)[0] ?? '';

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($firstBlock), ENT_QUOTES | ENT_HTML5)));
    }
}
