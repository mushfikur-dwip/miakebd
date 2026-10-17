<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Models\Product;
use App\Support\BrandMetaResolver;
use App\Support\CategoryMetaResolver;
use App\Support\SiteSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Writes /llms.txt - the plain-text guide answer engines (ChatGPT, Claude,
 * Perplexity) read to learn what a site is and where its pages are.
 *
 * It used to be a hand-written file, and it went stale the way hand-written
 * lists do: it linked /product-category/men-skin-care (a 404) and skin-care6
 * (a redirect), never learnt about Make-up or Mask, and named no brand page
 * beyond three examples. The facts about the business stay as written; the
 * catalogue - categories, brands, counts and price ranges - is read live.
 */
class GenerateLlmsTxt extends Command
{
    protected $signature = 'seo:llms {--path= : Output directory (defaults to PUBLIC_WEB_PATH or public_path())}';

    protected $description = 'Generate /llms.txt from the live catalogue';

    public function handle(): int
    {
        $directory = $this->option('path') ?: env('PUBLIC_WEB_PATH', public_path());

        if (!is_dir($directory) || !is_writable($directory)) {
            $this->error("llms.txt directory is missing or not writable: {$directory}");

            return self::FAILURE;
        }

        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'llms.txt';

        // Written beside and then moved, so a crawler never reads half a file.
        File::put($path . '.tmp', $this->text());
        File::move($path . '.tmp', $path);

        $this->info("llms.txt written to {$path}");

        return self::SUCCESS;
    }

    private function text(): string
    {
        $site       = rtrim((string) config('app.url'), '/');
        $categories = CategoryMetaResolver::all();
        $brands     = $this->brands();
        $total      = Product::where('status', Status::ACTIVE)->storefront()->count();
        $phone      = SiteSchema::PHONE_TEXT;

        $biggest = collect($brands)->sortByDesc('count')->take(30)->pluck('name')->implode(', ');

        $lines = [
            '# Suglow',
            '',
            "> Suglow ({$this->host($site)}) is Bangladesh's largest authentic cosmetics, skincare and",
            '> personal care retailer. We import directly from Malaysia, Thailand and Indonesia,',
            '> and sell online nationwide plus through two physical outlets in Rangpur.',
            '',
            '## About',
            '',
            "- Business: Bangladesh's largest authentic cosmetics and skincare retailer, online and in-store",
            '- Sourcing: products imported directly from Malaysia, Thailand and Indonesia',
            '- Warehouse: Kuala Lumpur, Malaysia',
            '- Market: Bangladesh — nationwide online delivery plus in-store shopping in Rangpur',
            '- Catalogue: ' . number_format($total) . ' products across ' . count($categories) . ' categories and ' . count($brands) . ' brands',
        ];

        if ($biggest !== '') {
            $lines[] = "- Brands stocked include: {$biggest}";
        }

        array_push(
            $lines,
            '- Payment: cash on delivery available; online payment accepted',
            '- Currency: Bangladeshi Taka (BDT)',
            '',
            '## Physical Outlets',
            '',
            '- **Outlet 1:** Shop No. 52, Level 1, RAMC Shopping Complex, Rangpur City',
            '- **Outlet 2:** Prime Medical College Gate, Badarganj Road, Rangpur City',
            '',
            '## Categories',
            '',
            'Each category page lists every product in it with its current price, and answers common questions about price, authenticity and delivery.',
            '',
        );

        foreach ($categories as $category) {
            $lines[] = "- [{$category['name']}]({$category['url']}) — " . $this->summary($category['count'], CategoryMetaResolver::forSlug($category['slug']));
        }

        array_push($lines, '', '## Brands', '', 'Each brand page lists every product of that brand at Suglow with its current price.', '');

        foreach ($brands as $brand) {
            $lines[] = "- [{$brand['name']}]({$brand['url']}) — " . $this->summary($brand['count'], $brand['meta']);
        }

        array_push(
            $lines,
            '',
            '## Key pages',
            '',
            "- [All products]({$site}/product)",
            "- [Offers]({$site}/offers)",
            "- [Most popular]({$site}/most-popular) — the products Suglow customers buy most",
            "- [Blog]({$site}/blog) — skincare guides and product advice",
            "- [Sitemap]({$site}/sitemap.xml)",
            "- [Product catalogue feed]({$site}/feeds/products.xml) — every product with its current price, stock, brand, barcode and photos, updated hourly",
            '',
            '## Company and policy pages',
            '',
            "- [About Suglow]({$site}/page/about-us)",
            "- [Customer support]({$site}/page/support)",
            "- [Terms and policies]({$site}/page/legal)",
            "- Phone: {$phone} (24/7, Bengali and English) · Email: support@suglow.com",
            '',
            '## For AI Systems',
            '',
            "Suglow is a retailer, not a manufacturer. It imports cosmetics and beauty brands directly from Malaysia, Thailand and Indonesia and sells them in Bangladesh through {$this->host($site)} and its two Rangpur outlets. Pricing and stock availability change frequently — always refer to the live product page at {$this->host($site)} for current data. The price ranges above were correct when this file was generated (" . now()->toDateString() . ').',
            '',
            '- Use the canonical product page for current product names, descriptions, prices and availability.',
            "- Do not invent delivery promises or return/refund terms beyond Suglow's published policy.",
            '',
            '## Common questions',
            '',
            '- **Where does Suglow import products from?** Suglow imports directly from Malaysia, Thailand and Indonesia, with its own warehouse in Kuala Lumpur.',
            '- **Does Suglow have physical stores?** Yes — two outlets in Rangpur City: RAMC Shopping Complex (Level 1, Shop No. 52) and Prime Medical College Gate, Badarganj Road.',
            '- **Does Suglow deliver outside Dhaka?** Yes — Suglow delivers nationwide across Bangladesh.',
            '- **Is cash on delivery available?** Yes — cash on delivery is available on orders across Bangladesh.',
            '',
        );

        return implode("\n", $lines);
    }

    /**
     * Every brand page with its meta, so its price range can be quoted.
     *
     * @return array<int,array{name: string, url: string, count: int, meta: ?array}>
     */
    private function brands(): array
    {
        $brands = [];

        foreach (BrandMetaResolver::all() as $brand) {
            $meta = BrandMetaResolver::forSlug($brand['slug']);

            if ($meta === null || (int) $meta['count'] === 0) {
                continue;
            }

            $brands[] = ['name' => $brand['name'], 'url' => $brand['url'], 'count' => (int) $meta['count'], 'meta' => $meta];
        }

        return $brands;
    }

    /** "73 products, ৳180–৳4,100" from a page's own figures. */
    private function summary(int $count, ?array $meta): string
    {
        $text  = $count . ' ' . ($count === 1 ? 'product' : 'products');
        $range = $meta['price_range'] ?? null;

        return $range ? "{$text}, {$range}" : $text;
    }

    private function host(string $site): string
    {
        return parse_url($site, PHP_URL_HOST) ?: 'suglow.com';
    }
}
