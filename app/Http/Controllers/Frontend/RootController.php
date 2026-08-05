<?php

namespace App\Http\Controllers\Frontend;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Models\Analytic;
use App\Models\Product;
use App\Models\ThemeSetting;
use App\Support\BlogMetaResolver;
use App\Support\CategoryMetaResolver;
use App\Support\SeoSchema;

class RootController extends Controller
{
    public function index(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        return $this->shell();
    }

    public function product(Product $product): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        abort_unless($product->status === Status::ACTIVE, 404);

        $product = Product::query()
            // reviews.user feeds the Review markup in SeoSchema. Eager-loaded
            // so five reviews do not become five extra queries per page render.
            ->with(['seo.media', 'media', 'category', 'brand', 'variations', 'reviews.user'])
            ->withSum('stockItems', 'quantity')
            ->withReviewRating()
            ->findOrFail($product->id);

        $description = SeoSchema::plainText($product->seo?->description ?: $product->description ?: $product->name);
        $title = $product->seo?->title ?: $product->name;
        $keywordValues = json_decode((string) $product->seo?->meta_keyword, true);
        if (!is_array($keywordValues)) {
            // Legacy rows stored as a plain comma-separated string.
            $keywordValues = array_values(array_filter(array_map('trim', explode(',', (string) $product->seo?->meta_keyword))));
        }
        $keywords = implode(', ', $keywordValues);
        // Config-derived, not route(): route() builds against whatever host the
        // request arrived on, so a hit on the bare IP, on http rather than
        // https, or on www would emit a canonical pointing at that variant —
        // which is precisely how a page ends up splitting its own ranking.
        $canonical = rtrim((string) config('app.url'), '/') . '/product/' . rawurlencode($product->slug);
        $image = $product->seo?->cover ?: $product->cover;

        $structuredData = SeoSchema::product($product);

        // Commerce facts for the og/product:* tags Facebook renders under a
        // link preview. Read back out of the schema rather than recomputed, so
        // the price and availability in the preview cannot drift from the
        // price and availability in the JSON-LD.
        $commerce = [
            'price' => $structuredData['offers']['price'] ?? null,
            'currency' => $structuredData['offers']['priceCurrency'] ?? 'BDT',
            'availability' => $structuredData['offers']['availability'] ?? null,
            'brand' => $product->brand?->name,
            'sku' => $product->sku,
        ];

        return $this->shell([
            'seo' => compact('title', 'description', 'keywords', 'canonical', 'image')
                + ['type' => 'product', 'robots' => 'index, follow, max-image-preview:large']
                + ['commerce' => $commerce],
            // productPage() = the Product schema above plus a BreadcrumbList
            // in one @graph. Commerce keeps reading $structuredData so the
            // og/product:* tags still cannot drift from the offers block.
            'structuredData' => SeoSchema::productPage($product),
        ]);
    }

    /**
     * /product-category/{slug} — the clean category URL.
     *
     * Vue owns the same path client-side, so this only has to make the raw HTML
     * correct for crawlers. An unknown or inactive slug 404s rather than
     * rendering an empty listing under a real-looking URL.
     */
    public function category(string $slug): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        $meta = CategoryMetaResolver::forSlug($slug);

        abort_if($meta === null, 404);

        return $this->shell([
            'seo' => [
                'title' => $meta['title'],
                'description' => $meta['description'],
                'keywords' => $meta['keywords'],
                'canonical' => $meta['url'],
                'image' => $meta['image'],
                'type' => 'website',
                'robots' => 'index, follow, max-image-preview:large',
            ],
            'structuredData' => CategoryMetaResolver::structuredData($meta),
            'category' => $meta,
        ]);
    }

    /**
     * /blog — the magazine landing page.
     */
    public function blogIndex(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        return $this->shell([
            'seo'            => BlogMetaResolver::forIndex(),
            'structuredData' => BlogMetaResolver::indexStructuredData(),
        ]);
    }

    /**
     * /blog/{slug} — a single article.
     *
     * An unknown or unpublished slug 404s rather than rendering the SPA shell
     * under a real-looking URL, which is what would otherwise let a draft be
     * shared and indexed.
     */
    public function blogPost(string $slug): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        $meta = BlogMetaResolver::forPost($slug);

        abort_if($meta === null, 404);

        return $this->shell([
            'seo'            => $meta,
            'structuredData' => BlogMetaResolver::postStructuredData($slug),
            'blogPost'       => $meta,
        ]);
    }

    /**
     * /blog/category/{slug} — the topic landing page.
     */
    public function blogCategory(string $slug): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        $meta = BlogMetaResolver::forCategory($slug);

        abort_if($meta === null, 404);

        return $this->shell([
            'seo'            => $meta,
            'structuredData' => BlogMetaResolver::categoryStructuredData($meta),
            'blogCategory'   => $meta,
        ]);
    }

    /**
     * /blog/tag/{slug} — a concern landing page (acne, sunburn, tan).
     */
    public function blogTag(string $slug): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        $meta = BlogMetaResolver::forTag($slug);

        abort_if($meta === null, 404);

        return $this->shell([
            'seo'            => $meta,
            'structuredData' => BlogMetaResolver::tagStructuredData($meta),
            'blogCategory'   => $meta,
        ]);
    }

    private function shell(array $data = []): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        $analytics = Analytic::with('analyticSections')->where(['status' => Status::ACTIVE])->get();
        $themeFavicon = ThemeSetting::where(['key' => 'theme_favicon_logo'])->first();

        return view('master', $data + [
            'analytics' => $analytics,
            'favicon' => $themeFavicon?->faviconLogo,
        ]);
    }
}
