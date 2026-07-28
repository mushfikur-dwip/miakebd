<?php
/**
 * suglow.com — Sitemap generator (FIXED for dev/ + public_html/ hosting layout)
 *
 * UPLOAD TO:  dev/app/Console/Commands/GenerateSitemap.php
 *
 * INSTALL FIRST (needs Terminal/SSH):
 *   composer require spatie/laravel-sitemap
 *
 * ADD TO dev/.env :
 *   PUBLIC_WEB_PATH=/home/YOURCPANELUSER/public_html
 *   (find your exact path in cPanel — top-left of File Manager shows it)
 *
 * RUN:
 *   php artisan sitemap:generate
 *
 * BUG 1 FIX: writes into public_html (your real web root), not dev/public
 *            which is unreachable from the internet on your setup.
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

class GenerateSitemap extends Command
{
    protected $signature   = 'sitemap:generate {--path= : Override output directory}';
    protected $description = 'Generate sitemap.xml into the public web root';

    public function handle(): int
    {
        $dir = $this->resolveWebRoot();

        if (! $dir) {
            $this->error('Could not find your public web root.');
            $this->line('Add this to dev/.env  (use your real cPanel username):');
            $this->line('  PUBLIC_WEB_PATH=/home/YOURUSER/public_html');
            return 1;
        }

        $this->info("Writing sitemap into: {$dir}");

        $base    = rtrim(config('app.url', 'https://suglow.com'), '/');
        $sitemap = Sitemap::create();

        // --- Homepage ---
        $sitemap->add(
            Url::create($base . '/')
                ->setPriority(1.0)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_DAILY)
                ->setLastModificationDate(now())
        );

        // --- Static pages (only ones that exist) ---
        foreach (['about', 'contact', 'blog', 'brands'] as $slug) {
            $sitemap->add(
                Url::create("{$base}/{$slug}")
                    ->setPriority(0.6)
                    ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
            );
        }

        $catCount = 0;
        $prodCount = 0;

        // --- Categories (guarded: table may not exist / be named differently) ---
        if (Schema::hasTable('categories') && class_exists(\App\Models\Category::class)) {
            \App\Models\Category::query()
                ->select(['slug', 'updated_at'])
                ->chunk(200, function ($chunk) use ($sitemap, $base, &$catCount) {
                    foreach ($chunk as $cat) {
                        if (empty($cat->slug)) continue;
                        $sitemap->add(
                            Url::create("{$base}/category/{$cat->slug}")
                                ->setPriority(0.8)
                                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                                ->setLastModificationDate($cat->updated_at ?? now())
                        );
                        $catCount++;
                    }
                });
        } else {
            $this->warn('categories table not found — skipping category URLs.');
        }

        // --- Products ---
        if (Schema::hasTable('products') && class_exists(\App\Models\Product::class)) {
            $q = \App\Models\Product::query()->select(['slug', 'updated_at']);

            // only filter on is_active if that column actually exists
            if (Schema::hasColumn('products', 'is_active')) {
                $q->where('is_active', 1);
            }

            $q->chunk(200, function ($chunk) use ($sitemap, $base, &$prodCount) {
                foreach ($chunk as $p) {
                    if (empty($p->slug)) continue;
                    $sitemap->add(
                        Url::create("{$base}/product/{$p->slug}")
                            ->setPriority(0.9)
                            ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                            ->setLastModificationDate($p->updated_at ?? now())
                    );
                    $prodCount++;
                }
            });
        } else {
            $this->warn('products table not found — skipping product URLs.');
        }

        $file = rtrim($dir, '/') . '/sitemap.xml';
        $sitemap->writeToFile($file);

        $this->newLine();
        $this->info('Sitemap written.');
        $this->line("  File:       {$file}");
        $this->line("  Categories: {$catCount}");
        $this->line("  Products:   {$prodCount}");
        $this->newLine();
        $this->line('Verify with:  curl -I https://suglow.com/sitemap.xml');
        $this->line('Expect:       Content-Type: text/xml  (NOT text/html)');

        return 0;
    }

    /**
     * Find the real web root. Order:
     *   1. --path option
     *   2. PUBLIC_WEB_PATH in .env
     *   3. sibling public_html next to the Laravel root  (your layout)
     *   4. Laravel's own public/
     */
    private function resolveWebRoot(): ?string
    {
        $candidates = array_filter([
            $this->option('path'),
            env('PUBLIC_WEB_PATH'),
            base_path('../public_html'),
            public_path(),
        ]);

        foreach ($candidates as $c) {
            if (is_dir($c) && is_writable($c)) {
                return realpath($c);
            }
        }

        return null;
    }
}
