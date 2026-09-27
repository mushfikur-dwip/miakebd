<?php

namespace App\Http\Controllers\Frontend;

use App\Enums\Ask;
use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\SettingResource;
use App\Models\Analytic;
use App\Models\Product;
use App\Models\SlugRedirect;
use App\Models\ThemeSetting;
use App\Support\BlogMetaResolver;
use App\Support\BrandMetaResolver;
use App\Support\CategoryMetaResolver;
use App\Support\MediaUrl;
use App\Support\MetaPixel;
use App\Support\TikTokPixel;
use App\Support\SeoSchema;
use App\Services\SettingService;
use Illuminate\Support\Facades\Log;

class RootController extends Controller
{
    public function index(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        return $this->shell();
    }

    public function product(Product $product): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        abort_unless($product->status === Status::ACTIVE, 404);
        // Stocked for the till, not for the website.
        abort_if($product->pos_only == Ask::YES, 404);

        $product = Product::query()
            // reviews.user feeds the Review markup in SeoSchema. Eager-loaded
            // so five reviews do not become five extra queries per page render.
            ->with(['seo.media', 'media', 'category', 'brand', 'variations', 'reviews.user'])
            ->withSum('stockItems', 'quantity')
            ->withReviewRating()
            ->findOrFail($product->id);

        // The written SEO description, or - for the products added since the
        // SEO import, whose only description was their own name - one built
        // from the product's real facts. See SeoSchema::fallbackDescription().
        $description = SeoSchema::description($product);
        // "... Price in Bangladesh" is how shoppers here search for a product,
        // and it is the form the imported SEO titles already take.
        $title = SeoSchema::cleanName($product->seo?->title) ?: SeoSchema::cleanName($product->name) . ' Price in Bangladesh';
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
        // Decided on the media rows, not on the accessors.
        //
        // This used to read `$product->seo?->cover ?: $product->cover`, and the
        // `?:` never once fell through: ProductSeo::cover returns the generic
        // placeholder when no SEO image was uploaded, and a placeholder path is
        // a non-empty string. So every product that had a product_seos row
        // without its own image - which is nearly all of them - advertised
        // /images/default/seo/cover.png to WhatsApp and Facebook instead of its
        // own photo. The one product anyone had uploaded an SEO image for was
        // also the one product whose link preview worked.
        //
        // Picking the media first means the URL and the dimensions below are
        // taken from the same decision, so the size can never describe a
        // different picture than the one og:image points at.
        $seoMedia     = $product->seo?->getMedia('product-seo')->last();
        $productMedia = $product->getMedia('product')->first();

        $imageMedia = $seoMedia ?: $productMedia;
        $image      = $seoMedia ? $product->seo->cover : $product->cover;
        $imageSize  = MediaUrl::dimensions($imageMedia, 'cover');

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
                + ['image_width' => $imageSize[0] ?? null, 'image_height' => $imageSize[1] ?? null]
                + ['type' => 'product', 'robots' => 'index, follow, max-image-preview:large']
                + ['commerce' => $commerce],
            // productPage() = the Product schema above plus a BreadcrumbList
            // in one @graph. Commerce keeps reading $structuredData so the
            // og/product:* tags still cannot drift from the offers block.
            'structuredData' => SeoSchema::productPage($product),
            'productPage' => $this->productFacts($product, $structuredData),
        ]);
    }

    /**
     * What the page says about the product, as plain HTML for readers that
     * never run JavaScript - ChatGPT, Perplexity, Claude and most AI crawlers,
     * and Google before it renders. Until now they saw the product's name and
     * a phone number; the price, brand, description and questions-and-answers
     * existed only inside the Vue app.
     *
     * Everything here is what the app itself shows the customer, so it is the
     * same page described twice, not separate content for robots.
     */
    private function productFacts(Product $product, array $schema): array
    {
        $siteUrl  = rtrim((string) config('app.url'), '/');
        $pricing  = SeoSchema::pricing($product);
        $category = $product->category;

        $paragraphs = SeoSchema::hasRealDescription($product)
            ? SeoSchema::paragraphs($product->description ?: $product->seo?->description)
            : [SeoSchema::fallbackDescription($product)];

        // Products from the same category: the links a crawler follows to the
        // rest of the catalogue, since the app's own "related products" row is
        // drawn by JavaScript it never runs.
        $related = [];
        try {
            if ($category) {
                $related = Product::query()
                    ->select(['id', 'name', 'slug'])
                    ->where('product_category_id', $category->id)
                    ->where('id', '<>', $product->id)
                    ->where('status', Status::ACTIVE)
                    ->storefront()
                    ->whereNotNull('slug')
                    ->where('slug', '<>', '')
                    ->latest('id')
                    ->limit(12)
                    ->get()
                    ->map(fn ($item) => [
                        'name' => SeoSchema::cleanName($item->name),
                        'url'  => $siteUrl . '/product/' . rawurlencode($item->slug),
                    ])
                    ->all();
            }
        } catch (\Throwable $e) {
            $related = [];
        }

        return [
            'name'          => SeoSchema::cleanName($product->name),
            'brand'         => SeoSchema::brandName($product),
            'brand_url'     => SeoSchema::brandName($product)
                ? (filled($product->brand->slug) ? BrandMetaResolver::url($product->brand->slug) : $siteUrl . '/product?brand=' . $product->brand->id)
                : null,
            'category'      => SeoSchema::cleanName($category?->name) ?: null,
            'category_url'  => $category?->slug ? $siteUrl . '/product-category/' . rawurlencode($category->slug) : null,
            'price'         => $pricing['current'],
            'regular_price' => $pricing['on_sale'] ? $pricing['regular'] : null,
            'in_stock'      => SeoSchema::isInStock($product),
            'sku'           => $product->sku,
            'gtin'          => ($gtin = SeoSchema::gtinFor($product)) ? (string) reset($gtin) : null,
            'image'         => $schema['image'][0] ?? null,
            'paragraphs'    => array_slice($paragraphs, 0, 60),
            'related'       => $related,
        ];
    }

    /**
     * /product-category/{slug} — the clean category URL.
     *
     * Vue owns the same path client-side, so this only has to make the raw HTML
     * correct for crawlers. An unknown or inactive slug 404s rather than
     * rendering an empty listing under a real-looking URL.
     */
    public function category(string $slug): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse
    {
        $meta = CategoryMetaResolver::forSlug($slug);

        // A renamed category (or one whose slug the old "NULL" bug mangled):
        // send search engines and shared links on to the page's new address,
        // passing its ranking along, instead of a dead end.
        if ($meta === null && ($target = SlugRedirect::target(SlugRedirect::PRODUCT_CATEGORY, $slug))) {
            return redirect()->to(
                rtrim((string) config('app.url'), '/') . '/product-category/' . rawurlencode($target),
                301
            );
        }

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
     * /brand/{slug} — a brand's own page: "CeraVe price in Bangladesh".
     *
     * Vue renders the listing on the same path; this makes the raw HTML right
     * for crawlers. An unknown, inactive or placeholder brand 404s.
     */
    public function brand(string $slug): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        $meta = BrandMetaResolver::forSlug($slug);

        abort_if($meta === null, 404);

        return $this->shell([
            'seo' => [
                'title'       => $meta['title'],
                'description' => $meta['description'],
                'keywords'    => $meta['keywords'],
                'canonical'   => $meta['url'],
                'image'       => $meta['image'],
                'type'        => 'website',
                'robots'      => $meta['robots'],
            ],
            'structuredData' => BrandMetaResolver::structuredData($meta),
            'brandPage'      => $meta,
        ]);
    }

    /**
     * /product — every product. Its own title, and links to every brand page
     * for crawlers, which cannot run the SPA listing.
     */
    public function listing(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        $siteUrl = rtrim((string) config('app.url'), '/');

        return $this->shell([
            'seo' => [
                'title'       => 'All Products — Authentic Cosmetics & Skincare Price in Bangladesh | Suglow',
                'description' => 'Browse every authentic cosmetic, skincare, hair care and fragrance product at Suglow. Original imports, cash on delivery across Bangladesh.',
                'keywords'    => null,
                // Query-string variants (?brand=, ?name=, sorting) all
                // canonicalise here, so they never compete with each other.
                'canonical'   => $siteUrl . '/product',
                'image'       => null,
                'type'        => 'website',
                'robots'      => 'index, follow, max-image-preview:large',
            ],
            'listingPage' => ['brands' => BrandMetaResolver::all()],
        ]);
    }

    /**
     * /offers — the discounts page.
     */
    public function offers(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        return $this->shell([
            'seo' => [
                'title'       => 'Offers & Discounts on Authentic Cosmetics in Bangladesh | Suglow',
                'description' => 'Current offers and discounts on authentic skincare, makeup and cosmetics at Suglow. Limited-time prices, cash on delivery across Bangladesh.',
                'keywords'    => null,
                'canonical'   => rtrim((string) config('app.url'), '/') . '/offers',
                'image'       => null,
                'type'        => 'website',
                'robots'      => 'index, follow, max-image-preview:large',
            ],
        ]);
    }

    /**
     * /most-popular — the best sellers. Fell through to the generic title.
     */
    public function mostPopular(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        return $this->shell([
            'seo' => [
                'title'       => 'Most Popular Cosmetics & Skincare in Bangladesh | Suglow',
                'description' => 'The skincare, makeup and beauty products Suglow customers buy most. 100% authentic, cash on delivery across Bangladesh.',
                'keywords'    => null,
                'canonical'   => rtrim((string) config('app.url'), '/') . '/most-popular',
                'image'       => null,
                'type'        => 'website',
                'robots'      => 'index, follow, max-image-preview:large',
            ],
        ]);
    }

    /**
     * /login — its own title for the browser tab and bookmarks, and kept out
     * of search results: an account page has nothing to rank for.
     */
    public function login(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        return $this->shell([
            'seo' => [
                'title'       => 'Log In to Your Account | Suglow',
                'description' => 'Log in to your Suglow account to track orders, use your wallet balance and check out faster.',
                'keywords'    => null,
                'canonical'   => rtrim((string) config('app.url'), '/') . '/login',
                'image'       => null,
                'type'        => 'website',
                'robots'      => 'noindex, follow',
            ],
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
            // Handed to the SPA in the HTML so it can draw the header, logo,
            // currency and menus immediately. It used to fetch
            // /api/frontend/setting first and sit on a loading state until that
            // answered - a whole extra round trip after the bundle, on the
            // slowest visit of all: the first one. The settings package caches
            // these groups, so building it here costs almost nothing.
            'bootSetting' => $this->bootSetting(),
            // Meta Pixel: the id, and whether this page still has to load the
            // base code (it must not when the shop already pasted the snippet
            // into Analytics). See App\Support\MetaPixel.
            'metaPixel' => MetaPixel::resolve($analytics),
            'tiktokPixel' => TikTokPixel::resolve($analytics),
        ]);
    }

    /**
     * The same payload GET /api/frontend/setting returns.
     *
     * Wrapped so a failure here can never take the page down with it: the SPA
     * falls back to fetching the endpoint itself when this is empty, which is
     * exactly what it did before.
     */
    private function bootSetting(): array
    {
        try {
            return (new SettingResource(app(SettingService::class)->list()))->toArray(request());
        } catch (\Throwable $e) {
            Log::warning('Could not inline the storefront settings: ' . $e->getMessage());

            return [];
        }
    }
}
