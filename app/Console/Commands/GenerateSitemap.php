<?php

namespace App\Console\Commands;

use App\Enums\Status;
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

        $count = 0;
        $this->writeUrl($writer, "{$baseUrl}/", now(), 'daily', '1.0');
        $this->writeUrl($writer, "{$baseUrl}/product", now(), 'daily', '0.8');
        $count += 2;

        Product::query()
            ->select(['id', 'slug', 'updated_at'])
            ->where('status', Status::ACTIVE)
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
                        '0.9'
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
        string $priority
    ): void {
        $writer->startElement('url');
        $writer->writeElement('loc', $location);

        if ($lastModified) {
            $writer->writeElement('lastmod', $lastModified->toAtomString());
        }

        $writer->writeElement('changefreq', $changeFrequency);
        $writer->writeElement('priority', $priority);
        $writer->endElement();
    }
}
