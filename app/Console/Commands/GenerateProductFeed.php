<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Models\Product;
use App\Services\MetaConversionsService;
use App\Support\SeoSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * The product catalogue feed: public/feeds/products.xml.
 *
 * One file, two readers. Meta Commerce Manager reads it as the catalogue that
 * Advantage+ catalogue ads draw from - "viewed this serum" becomes an ad for
 * that serum at today's price - and Google Merchant Center reads it for the
 * free product listings in Google's Shopping tab. Both accept Google's RSS 2.0
 * product format, so it is written once in that.
 *
 * `g:id` is the same value the pixel and the Conversions API send as
 * content_ids (META_CONTENT_ID), which is the whole point: an event only
 * becomes a product ad when its id is found in here.
 *
 * Every figure comes from the helpers the product page's structured data uses
 * - price, sale price, stock, GTIN - so the feed, the page and the markup
 * cannot disagree. Both platforms disapprove items where they do.
 *
 * Left out on purpose, because both platforms would reject them with a
 * warning: POS-only products, and products whose only picture is the
 * placeholder. The count of each is printed so they can be fixed at source.
 *
 * Scheduled hourly (routes/console.php). Written to a temporary file and moved
 * into place, so a platform fetching mid-write never gets half a feed.
 */
class GenerateProductFeed extends Command
{
    protected $signature = 'feeds:products {--path= : Where to write it (default public/feeds/products.xml)}';

    protected $description = 'Write the product catalogue feed for Meta Commerce Manager and Google Merchant Center';

    /** @var array<int,?string> category id => path, so each category is walked once. */
    private array $types = [];

    public function handle(MetaConversionsService $meta): int
    {
        $path = $this->option('path') ?: public_path('feeds/products.xml');
        File::ensureDirectoryExists(dirname($path));
        $temporary = $path . '.tmp';

        $siteUrl = rtrim((string) config('app.url'), '/');

        $xml = new \XMLWriter();
        $xml->openUri($temporary);
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('rss');
        $xml->writeAttribute('version', '2.0');
        $xml->writeAttribute('xmlns:g', 'http://base.google.com/ns/1.0');
        $xml->startElement('channel');
        $xml->writeElement('title', 'Suglow');
        $xml->writeElement('link', $siteUrl);
        $xml->writeElement('description', 'Authentic cosmetics and skincare, delivered across Bangladesh.');

        $written     = 0;
        $noImage     = 0;
        $noPrice     = 0;
        $missingFile = [];

        Product::query()
            ->with(['media', 'brand', 'category', 'variations', 'seo'])
            ->withSum('stockItems', 'quantity')
            ->where('status', Status::ACTIVE)
            ->storefront()
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->orderBy('id')
            ->chunk(200, function ($products) use ($xml, $meta, $siteUrl, &$written, &$noImage, &$noPrice, &$missingFile) {
                foreach ($products as $product) {
                    // Only photos whose file is really on disk. Ten products
                    // had media rows whose files were gone (uploads 1685-1727,
                    // September 2026): their image links 404'd, and Meta and
                    // Google reject a catalogue item whose image cannot be
                    // fetched. Better left out, and reported, until re-uploaded.
                    $previews = $product->previews;
                    $media    = $product->getMedia('product')->values();
                    $present  = [];
                    foreach ($previews as $index => $url) {
                        if ($url && isset($media[$index]) && $this->fileExists($media[$index])) {
                            $present[] = $url;
                        }
                    }

                    if ($previews && !$present) {
                        $missingFile[] = $product->id;
                    }

                    // Absolute, whatever the storage disk is configured to
                    // return: both platforms reject a relative image link.
                    $images = array_values(array_map(
                        fn ($url) => str_starts_with($url, '/') && !str_starts_with($url, '//') ? $siteUrl . $url : $url,
                        $present
                    ));

                    if (!$images) {
                        $noImage++;
                        continue;
                    }

                    $pricing = SeoSchema::pricing($product);

                    if ($pricing['regular'] <= 0) {
                        $noPrice++;
                        continue;
                    }

                    $this->item($xml, $product, $meta->contentId($product), $images, $pricing, $siteUrl);
                    $written++;
                }
            });

        $xml->endElement(); // channel
        $xml->endElement(); // rss
        $xml->endDocument();
        $xml->flush();
        unset($xml);

        File::move($temporary, $path);

        $this->info("Wrote {$written} products to feeds/products.xml.");
        if ($noImage || $noPrice) {
            $this->warn("Left out {$noImage} with no product photo and {$noPrice} with no price - add them in the admin panel to include them.");
        }

        if ($missingFile) {
            $message = 'Photo file missing on disk for product ids ' . implode(', ', $missingFile) . ' - re-upload their photos.';
            $this->warn($message);
            \Illuminate\Support\Facades\Log::warning('Product feed: ' . $message);
        }

        return self::SUCCESS;
    }

    /**
     * Whether the file the image link points at exists: the preview
     * conversion when it was generated, otherwise the original.
     */
    private function fileExists(\Spatie\MediaLibrary\MediaCollections\Models\Media $media): bool
    {
        try {
            if ($media->hasGeneratedConversion('preview') && is_file($media->getPath('preview'))) {
                return true;
            }

            return is_file($media->getPath());
        } catch (\Throwable $e) {
            // A disk that cannot be stat'ed (not local): keep the old behaviour.
            return true;
        }
    }

    private function item(\XMLWriter $xml, Product $product, string $id, array $images, array $pricing, string $siteUrl): void
    {
        $xml->startElement('item');

        $xml->writeElement('g:id', $id);
        $xml->writeElement('g:title', SeoSchema::limit(SeoSchema::cleanName($product->name), 150));
        $xml->writeElement('g:description', SeoSchema::limit($this->description($product), 5000));
        $xml->writeElement('g:link', $siteUrl . '/product/' . rawurlencode($product->slug));
        $xml->writeElement('g:image_link', $images[0]);
        foreach (array_slice($images, 1, 10) as $image) {
            $xml->writeElement('g:additional_image_link', $image);
        }

        $xml->writeElement('g:availability', SeoSchema::isInStock($product) ? 'in_stock' : 'out_of_stock');
        $xml->writeElement('g:condition', 'new');
        $xml->writeElement('g:price', number_format($pricing['regular'], 2, '.', '') . ' BDT');
        if ($pricing['on_sale']) {
            $xml->writeElement('g:sale_price', number_format($pricing['current'], 2, '.', '') . ' BDT');
            $xml->writeElement('g:sale_price_effective_date', $pricing['sale_starts'] . '/' . $pricing['sale_ends']);
        }

        $brand = SeoSchema::brandName($product);
        $gtin  = SeoSchema::gtinFor($product);

        if ($brand) {
            $xml->writeElement('g:brand', $brand);
        }
        if ($gtin) {
            $xml->writeElement('g:gtin', (string) reset($gtin));
        }
        // Without a manufacturer barcode the product cannot be matched to a
        // global catalogue entry; saying so is what stops Google flagging
        // every such item as "missing GTIN".
        if (!$gtin) {
            $xml->writeElement('g:identifier_exists', 'no');
        }

        if ($type = $this->productType($product)) {
            $xml->writeElement('g:product_type', $type);
        }

        $xml->endElement();
    }

    /** The whole written description, or the generated one. */
    private function description(Product $product): string
    {
        if (!SeoSchema::hasRealDescription($product)) {
            return SeoSchema::fallbackDescription($product);
        }

        $paragraphs = SeoSchema::paragraphs($product->description ?: $product->seo?->description);

        return $paragraphs ? implode("\n", $paragraphs) : SeoSchema::description($product);
    }

    /** "Skin Care > Face Wash": the shop's own category path. */
    private function productType(Product $product): ?string
    {
        $category = $product->category;

        if (!$category) {
            return null;
        }

        if (array_key_exists($category->id, $this->types)) {
            return $this->types[$category->id];
        }

        try {
            $names = $category->ancestorsAndSelf()->get()
                ->sortBy('depth')
                ->map(fn ($node) => SeoSchema::cleanName($node->name))
                ->filter()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            $names = [SeoSchema::cleanName($category->name)];
        }

        return $this->types[$category->id] = $names ? implode(' > ', $names) : null;
    }
}
