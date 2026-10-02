<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use XMLWriter;

class GenerateSitemap extends Command
{
    protected $signature = 'sitemap:generate {--path= : Output directory (defaults to PUBLIC_WEB_PATH or public_path())}';

    protected $description = 'Generate the public XML sitemap from active storefront records';

    public function handle(): int
    {
        $directory = $this->option('path') ?: env('PUBLIC_WEB_PATH', public_path());

        if (! is_dir($directory) || ! is_writable($directory)) {
            $this->error("Sitemap directory is missing or not writable: {$directory}");

            return self::FAILURE;
        }

        $baseUrl = rtrim((string) config('app.url'), '/');
        $path = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'sitemap.xml';
        $temporaryPath = $path.'.tmp';
        $writer = new XMLWriter;

        if (! $writer->openURI($temporaryPath)) {
            $this->error("Unable to open sitemap for writing: {$temporaryPath}");

            return self::FAILURE;
        }

        $writer->startDocument('1.0', 'UTF-8');
        $writer->setIndent(true);
        $writer->startElement('urlset');
        $writer->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        // Google's image extension: product photos are how cosmetics get found
        // in Google Images, and listing them here gets them crawled with the page.
        $writer->writeAttribute('xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1');

        $count = 0;
        $this->writeUrl($writer, "{$baseUrl}/", now(), 'daily', '1.0');
        $this->writeUrl($writer, "{$baseUrl}/product", now(), 'daily', '0.8');
        $count += 2;

        Product::query()
            ->select(['id', 'slug', 'updated_at'])
            ->with('media')
            ->where('status', Status::ACTIVE)
            ->storefront()
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->orderBy('id')
            ->chunkById(500, function ($products) use ($writer, $baseUrl, &$count): void {
                foreach ($products as $product) {
                    $this->writeUrl(
                        $writer,
                        "{$baseUrl}/product/".rawurlencode($product->slug),
                        $product->updated_at,
                        'weekly',
                        '0.9',
                        $this->productImages($product, $baseUrl)
                    );
                    $count++;
                }
            });

        // Clean category URLs. /product?category={slug} 301s here, so only the
        // canonical path is ever advertised.
        //
        // No whereNull('deleted_at') here: product_categories has no such
        // column and the query would fail with SQLSTATE[42S22].
        ProductCategory::query()
            ->select(['id', 'slug', 'updated_at'])
            ->where('status', Status::ACTIVE)
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->orderBy('id')
            ->chunkById(200, function ($categories) use ($writer, $baseUrl, &$count): void {
                foreach ($categories as $category) {
                    $this->writeUrl(
                        $writer,
                        "{$baseUrl}/product-category/".rawurlencode($category->slug),
                        $category->updated_at,
                        'weekly',
                        '0.8'
                    );
                    $count++;
                }
            });

        // Brand pages ("CeraVe price in Bangladesh"). Only brands with products
        // on the website - an empty brand page is served as noindex, so
        // advertising it here would contradict the page itself.
        foreach (\App\Support\BrandMetaResolver::all() as $brand) {
            $this->writeUrl($writer, $brand['url'], $brand['updated_at'] ?? now(), 'weekly', '0.8');
            $count++;
        }

        $this->writeUrl($writer, "{$baseUrl}/offers", now(), 'daily', '0.7');
        $this->writeUrl($writer, "{$baseUrl}/most-popular", now(), 'daily', '0.7');
        $count += 2;

        Page::query()
            ->select(['id', 'slug', 'updated_at', 'status'])
            ->where('status', Status::ACTIVE)
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->orderBy('id')
            ->chunkById(200, function ($pages) use ($writer, $baseUrl, &$count): void {
                foreach ($pages as $page) {
                    $this->writeUrl(
                        $writer,
                        "{$baseUrl}/page/".rawurlencode($page->slug),
                        $page->updated_at,
                        'monthly',
                        '0.6'
                    );
                    $count++;
                }
            });

        // Blog. The index changes as often as posts are added; articles are
        // advertised at 0.7 — below products, since they earn traffic rather
        // than revenue directly, but above CMS pages.
        $this->writeUrl($writer, "{$baseUrl}/blog", now(), 'daily', '0.8');
        $count++;

        BlogCategory::query()
            ->select(['id', 'slug', 'updated_at'])
            ->where('status', Status::ACTIVE)
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->orderBy('id')
            ->chunkById(200, function ($categories) use ($writer, $baseUrl, &$count): void {
                foreach ($categories as $category) {
                    $this->writeUrl(
                        $writer,
                        "{$baseUrl}/blog/category/".rawurlencode($category->slug),
                        $category->updated_at,
                        'weekly',
                        '0.7'
                    );
                    $count++;
                }
            });

        // Concern landing pages. Only those with published posts behind them —
        // BlogMetaResolver 404s an empty concern, so listing one would send
        // Google to a dead URL.
        BlogTag::query()
            ->where('status', Status::ACTIVE)
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->whereHas('posts', fn($query) => $query->published())
            ->orderBy('id')
            ->chunkById(200, function ($tags) use ($writer, $baseUrl, &$count): void {
                foreach ($tags as $tag) {
                    $this->writeUrl(
                        $writer,
                        "{$baseUrl}/blog/tag/".rawurlencode($tag->slug),
                        $tag->updated_at,
                        'weekly',
                        '0.7'
                    );
                    $count++;
                }
            });

        // published() rather than a bare status check: a draft or a post dated
        // for next week must not be advertised, or Google crawls a 404.
        BlogPost::query()
            ->published()
            ->select(['id', 'slug', 'updated_at'])
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->orderBy('id')
            ->chunkById(500, function ($posts) use ($writer, $baseUrl, &$count): void {
                foreach ($posts as $post) {
                    $this->writeUrl(
                        $writer,
                        "{$baseUrl}/blog/".rawurlencode($post->slug),
                        $post->updated_at,
                        'monthly',
                        '0.7'
                    );
                    $count++;
                }
            });

        $writer->endElement();
        $writer->endDocument();
        $writer->flush();

        File::move($temporaryPath, $path);
        $this->info("Generated {$count} URLs at {$path}");

        return self::SUCCESS;
    }

    private function writeUrl(
        XMLWriter $writer,
        string $location,
        mixed $lastModified,
        string $changeFrequency,
        string $priority,
        array $images = []
    ): void {
        $writer->startElement('url');
        $writer->writeElement('loc', $location);

        if ($lastModified) {
            $writer->writeElement('lastmod', $lastModified->toAtomString());
        }

        $writer->writeElement('changefreq', $changeFrequency);
        $writer->writeElement('priority', $priority);

        foreach ($images as $image) {
            $writer->startElement('image:image');
            $writer->writeElement('image:loc', $image);
            $writer->endElement();
        }

        $writer->endElement();
    }

    /**
     * The product's own photos, as the JSON-LD lists them. The stock "no
     * image" placeholder is not a photo of anything, so it is never listed.
     * Google reads up to 1,000 per page; ten is plenty for a product. A
     * sitemap needs absolute URLs, so a disk configured with a relative
     * /storage URL is anchored to the site.
     *
     * @return list<string>
     */
    private function productImages(Product $product, string $baseUrl): array
    {
        try {
            $urls = $product->previews ?: [$product->cover];
        } catch (\Throwable $e) {
            return [];
        }

        $urls = array_filter(
            $urls,
            fn ($url) => is_string($url) && $url !== '' && !str_contains($url, '/images/default/')
        );

        return array_slice(array_values(array_map(
            fn (string $url) => str_starts_with($url, '/') ? $baseUrl . $url : $url,
            $urls
        )), 0, 10);
    }
}
