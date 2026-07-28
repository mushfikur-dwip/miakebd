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
        $inStock = $product->show_stock_out == Activity::DISABLE
            ? ($product->can_purchasable == Ask::NO ? false : (int) $product->stock_items_sum_quantity > 0)
            : true;
        $url = route('product.show', ['product' => $product->slug]);
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
