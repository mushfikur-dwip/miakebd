<?php

namespace App\Support;

use App\Enums\Activity;
use App\Enums\Ask;
use App\Libraries\AppLibrary;
use App\Models\Product;

class SeoSchema
{
    public static function product(Product $product): array
    {
        $price = count($product->variations) > 0 ? $product->variation_price : $product->selling_price;
        $currentPrice = AppLibrary::isBetweenDate($product->offer_start_date, $product->offer_end_date)
            ? $price - (($price / 100) * $product->discount)
            : $price;
        $inStock = self::isInStock($product);
        // Config-derived for the same reason as the canonical in
        // RootController: this URL becomes the schema @id and Offer.url, and
        // must not vary with the host the request happened to use.
        $url = rtrim((string) config('app.url'), '/') . '/product/' . rawurlencode($product->slug);
        $description = self::plainText($product->seo?->description ?: $product->description ?: $product->name);

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            '@id' => $url.'#product',
            'name' => $product->name,
            'description' => $description,
            'url' => $url,
            'image' => array_values(array_filter($product->previews ?: [$product->cover])),
            'sku' => $product->sku,
            'category' => $product->category?->name,
            'offers' => [
                '@type' => 'Offer',
                'url' => $url,
                'priceCurrency' => 'BDT',
                'price' => number_format((float) $currentPrice, 2, '.', ''),
                'availability' => $inStock
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
            ],
        ];

        if ($product->brand?->name) {
            $schema['brand'] = ['@type' => 'Brand', 'name' => $product->brand->name];
        }

        if ((int) $product->rating_star_count > 0 && (float) $product->rating_star > 0) {
            $schema['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => round((float) $product->rating_star / (int) $product->rating_star_count, 2),
                'reviewCount' => (int) $product->rating_star_count,
            ];
        }

        return array_filter($schema, fn ($value) => $value !== null && $value !== '');
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
