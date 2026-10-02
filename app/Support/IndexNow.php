<?php

namespace App\Support;

use App\Enums\Status;
use App\Models\BlogPost;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * IndexNow: one POST tells Bing (and through it ChatGPT Search, Copilot,
 * DuckDuckGo and Yahoo), Yandex, Seznam and Naver which pages are new,
 * changed or gone - so a new product is searchable within the hour instead of
 * whenever a crawler next comes round. Google does not take part; it reads the
 * sitemap.
 *
 * The key is derived from APP_KEY, so there is nothing to configure: it is
 * public by design (served at /{key}.txt for the engines to verify the site)
 * and the HMAC reveals nothing about APP_KEY.
 */
class IndexNow
{
    public const LAST_RUN_KEY = 'indexnow:last-run';

    /** The protocol's limit per request. */
    private const BATCH = 10000;

    public static function key(): string
    {
        return substr(hash_hmac('sha256', 'indexnow', (string) config('app.key')), 0, 32);
    }

    /**
     * Only the live site submits: a developer machine or a staging copy must
     * never announce its URLs. INDEXNOW_ENABLED overrides either way.
     */
    public static function enabled(): bool
    {
        $setting = config('services.indexnow.enabled');

        if ($setting !== null && $setting !== '') {
            return filter_var($setting, FILTER_VALIDATE_BOOLEAN);
        }

        return app()->environment('production');
    }

    /**
     * Absolute URLs to announce. With no $since: every live page. With $since:
     * whatever changed from then on - including products switched off, made
     * till-only or deleted, whose now-404 address tells the engines to drop it.
     * ">=", not ">": an edit in the same second as the last run is sent twice
     * rather than never.
     *
     * @return list<string>
     */
    public static function urls(?Carbon $since): array
    {
        $site = rtrim((string) config('app.url'), '/');
        $urls = [];

        $changed = fn ($query) => $since ? $query->where('updated_at', '>=', $since) : $query;

        $products = Product::query()->select(['slug'])->whereNotNull('slug')->where('slug', '<>', '');
        if (!$since) {
            $products->where('status', Status::ACTIVE)->storefront();
        }
        foreach ($changed($products)->pluck('slug') as $slug) {
            $urls[] = $site . '/product/' . rawurlencode($slug);
        }

        if ($since) {
            foreach (Product::onlyTrashed()->whereNotNull('slug')->where('deleted_at', '>=', $since)->pluck('slug') as $slug) {
                $urls[] = $site . '/product/' . rawurlencode($slug);
            }
        }

        foreach ($changed(ProductCategory::query()->where('status', Status::ACTIVE)->whereNotNull('slug')->where('slug', '<>', ''))->pluck('slug') as $slug) {
            $urls[] = $site . '/product-category/' . rawurlencode($slug);
        }

        foreach (BrandMetaResolver::all() as $brand) {
            if (!$since || (isset($brand['updated_at']) && $brand['updated_at'] && Carbon::parse($brand['updated_at'])->gte($since))) {
                $urls[] = $brand['url'];
            }
        }

        foreach ($changed(Page::query()->where('status', Status::ACTIVE)->whereNotNull('slug')->where('slug', '<>', ''))->pluck('slug') as $slug) {
            $urls[] = $site . '/page/' . rawurlencode($slug);
        }

        foreach ($changed(BlogPost::query()->published()->whereNotNull('slug')->where('slug', '<>', ''))->pluck('slug') as $slug) {
            $urls[] = $site . '/blog/' . rawurlencode($slug);
        }

        if (!$since) {
            foreach (['/', '/product', '/offers', '/most-popular', '/blog'] as $path) {
                $urls[] = $site . $path;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * True when every batch was accepted: 200, or 202 while the engines are
     * still verifying the key file.
     */
    public static function submit(array $urls): bool
    {
        $site = rtrim((string) config('app.url'), '/');
        $ok   = true;

        foreach (array_chunk(array_values($urls), self::BATCH) as $batch) {
            try {
                $response = Http::timeout(20)->acceptJson()->post((string) config('services.indexnow.endpoint', 'https://api.indexnow.org/indexnow'), [
                    'host'        => (string) parse_url($site, PHP_URL_HOST),
                    'key'         => self::key(),
                    'keyLocation' => $site . '/' . self::key() . '.txt',
                    'urlList'     => $batch,
                ]);

                if (!in_array($response->status(), [200, 202], true)) {
                    Log::warning('IndexNow refused a submission.', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);
                    $ok = false;
                }
            } catch (\Throwable $e) {
                Log::warning('IndexNow submission failed: ' . $e->getMessage());
                $ok = false;
            }
        }

        return $ok;
    }
}
