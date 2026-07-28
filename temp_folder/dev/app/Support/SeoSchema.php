<?php
/**
 * suglow.com — builds Product + FAQ JSON-LD structured data
 *
 * UPLOAD TO:  dev/app/Support/SeoSchema.php
 *
 * BUG 2 FIX (the important one)
 * My previous file tried to output JSON-LD from inside a Vue template using
 * <component :is="'script'">. Vue's compiler STRIPS <script> tags out of templates —
 * that is a hard limitation, so the structured data would never have appeared.
 * It also nested @graph inside the Product object, which is invalid schema.org.
 *
 * Structured data now renders server-side from Blade, so crawlers see it on the
 * very first request with no JavaScript involved.
 *
 * USED BY: your product controller (see ProductController-example.php)
 */

namespace App\Support;

class SeoSchema
{
    /**
     * Build the JSON-LD payload for a product page.
     * Returns a JSON string ready to print inside a <script type="application/ld+json">.
     */
    public static function product($product): string
    {
        $url = url('/product/' . $product->slug);

        // ---- Product ---------------------------------------------------------
        $node = [
            '@type'       => 'Product',
            '@id'         => $url . '#product',
            'name'        => $product->name,
            'url'         => $url,
            'description' => self::firstBlock($product->meta_description) ?: $product->name,
        ];

        if (! empty($product->image_url)) {
            $node['image'] = self::absolute($product->image_url);
        }

        if (! empty($product->brand)) {
            $node['brand'] = ['@type' => 'Brand', 'name' => $product->brand];
        }

        if (! empty($product->sku)) {
            $node['sku'] = (string) $product->sku;
        }

        // Only emit an Offer when we actually have a price.
        // A null price produces invalid markup and Google drops the whole block.
        if (is_numeric($product->price ?? null) && $product->price > 0) {
            $inStock = isset($product->stock) ? ($product->stock > 0) : true;

            $node['offers'] = [
                '@type'         => 'Offer',
                'url'           => $url,
                'priceCurrency' => 'BDT',
                'price'         => number_format((float) $product->price, 2, '.', ''),
                'availability'  => $inStock
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
                'priceValidUntil' => now()->addMonths(6)->toDateString(),
                'seller' => [
                    '@type' => 'Organization',
                    'name'  => 'Suglow',
                    'url'   => 'https://suglow.com',
                ],
            ];
        }

        // ---- FAQ (drives AEO / featured snippets / voice answers) -------------
        $faq = [
            '@type' => 'FAQPage',
            '@id'   => $url . '#faq',
            'mainEntity' => self::questions($product),
        ];

        // @graph belongs at the TOP level — this was malformed before.
        return json_encode(
            ['@context' => 'https://schema.org', '@graph' => [$node, $faq]],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /** Organisation + sitewide search box — put this on the homepage only. */
    public static function organisation(): string
    {
        return json_encode([
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type' => 'Organization',
                    '@id'   => 'https://suglow.com/#organization',
                    'name'  => 'Suglow',
                    'url'   => 'https://suglow.com',
                    'logo'  => 'https://suglow.com/logo.png',
                    'areaServed' => ['@type' => 'Country', 'name' => 'Bangladesh'],
                ],
                [
                    '@type' => 'WebSite',
                    '@id'   => 'https://suglow.com/#website',
                    'url'   => 'https://suglow.com',
                    'name'  => 'Suglow',
                    'publisher' => ['@id' => 'https://suglow.com/#organization'],
                    'potentialAction' => [
                        '@type'       => 'SearchAction',
                        'target'      => 'https://suglow.com/search?q={search_term_string}',
                        'query-input' => 'required name=search_term_string',
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ---------------------------------------------------------------- helpers

    private static function questions($product): array
    {
        $name  = $product->name;
        $price = is_numeric($product->price ?? null) && $product->price > 0
            ? '৳' . number_format((float) $product->price, 0) . ' BDT'
            : 'shown on the product page';

        $pairs = [
            ["Is {$name} available in Bangladesh?",
             "Yes. {$name} is available in Bangladesh from Suglow (suglow.com). It is 100% authentic, ships nationwide, and cash on delivery is accepted."],

            ["What is the price of {$name} in Bangladesh?",
             "The current price of {$name} in Bangladesh is {$price} at Suglow.com. Check the product page for any running offer."],

            ["Does Suglow offer cash on delivery for {$name}?",
             "Yes. Suglow accepts cash on delivery for {$name} across Bangladesh, with same-day dispatch inside Dhaka."],

            ["Is {$name} sold by Suglow original?",
             "Yes. Suglow sources {$name} through authorised channels and sells only original, authentic products."],
        ];

        return array_map(fn ($p) => [
            '@type' => 'Question',
            'name'  => $p[0],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $p[1]],
        ], $pairs);
    }

    /** The spreadsheet description holds 3 blocks; only the first is a meta description. */
    private static function firstBlock(?string $desc): string
    {
        if (! $desc) return '';
        $first = preg_split('/\R\s*\R/', trim($desc))[0] ?? '';
        return trim($first);
    }

    private static function absolute(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : url($path);
    }
}
