<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Product;
use App\Models\ProductBrand;
use Dipokhalder\Settings\Facades\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * /brand/{slug}: a brand's own indexable page - "CeraVe price in Bangladesh" -
 * and the listing pages around it.
 */
class BrandPageTest extends TestCase
{
    use RefreshDatabase;

    private ProductBrand $brand;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // As the live shop stores it - which is what made a case-sensitive
        // check append " | SUGLOW" to titles already ending in "Suglow".
        Settings::group('company')->set(['company_name' => 'SUGLOW']);

        $this->brand = ProductBrand::create(['name' => 'CeraVe', 'slug' => 'cerave', 'status' => Status::ACTIVE]);
    }

    private function product(string $name, array $attributes = []): Product
    {
        $n = ++$this->sequence;

        return Product::create($attributes + [
            'name'             => $name,
            'slug'             => 'product-' . $n,
            'sku'              => 'SKU' . $n,
            'status'           => Status::ACTIVE,
            'can_purchasable'  => Ask::YES,
            'buying_price'     => 800,
            'selling_price'    => 1650,
            'variation_price'  => 1650,
            'product_brand_id' => $this->brand->id,
        ]);
    }

    private function title(string $html): string
    {
        preg_match('/<title>([^<]*)<\/title>/', $html, $match);

        return html_entity_decode($match[1] ?? '');
    }

    private function noscript(string $html): string
    {
        preg_match('/<noscript>(.*?)<\/noscript>/s', explode('<body>', $html)[1] ?? '', $match);

        return $match[1] ?? '';
    }

    private function graph(string $html): array
    {
        preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $match);

        return json_decode($match[1], true)['@graph'] ?? [];
    }

    public function test_a_brand_has_its_own_page(): void
    {
        $cream = $this->product('CeraVe Moisturising Cream 454g');
        $tillOnly = $this->product('CeraVe Shop Only Lotion', ['pos_only' => Ask::YES]);

        $html = $this->get('/brand/cerave')->assertOk()->getContent();

        $this->assertSame('CeraVe Price in Bangladesh — 100% Authentic CeraVe | Suglow', $this->title($html));
        $this->assertStringContainsString('<link rel="canonical" href="' . url('/brand/cerave') . '">', $html);
        $this->assertStringContainsString('content="index, follow, max-image-preview:large"', $html);

        $types = array_column($this->graph($html), '@type');
        $this->assertSame(['CollectionPage', 'Brand', 'BreadcrumbList', 'ItemList'], $types);

        $text = $this->noscript($html);
        $this->assertStringContainsString('<h1>CeraVe Price in Bangladesh</h1>', $text);
        $this->assertStringContainsString('/product/' . $cream->slug, $text);
        $this->assertStringContainsString('৳1,650', $text);
        $this->assertStringNotContainsString('/product/' . $tillOnly->slug, $html, 'POS-only products 404 on the website');
    }

    public function test_a_brand_with_nothing_on_sale_is_kept_out_of_the_index(): void
    {
        $html = $this->get('/brand/cerave')->assertOk()->getContent();

        $this->assertStringContainsString('content="noindex, follow"', $html);
    }

    public function test_unknown_inactive_and_placeholder_brands_are_not_found(): void
    {
        ProductBrand::create(['name' => 'Old', 'slug' => 'old', 'status' => Status::INACTIVE]);
        ProductBrand::create(['name' => 'No Brand', 'slug' => 'no-brand', 'status' => Status::ACTIVE, 'is_default' => true]);

        $this->get('/brand/nobody')->assertNotFound();
        $this->get('/brand/old')->assertNotFound();
        $this->get('/brand/no-brand')->assertNotFound();
    }

    /** The site name once, whatever case it is stored in. */
    public function test_titles_do_not_repeat_the_site_name(): void
    {
        $this->product('CeraVe Cream');

        foreach (['/brand/cerave', '/product', '/offers'] as $path) {
            $title = $this->title($this->get($path)->assertOk()->getContent());

            $this->assertSame(1, substr_count(mb_strtolower($title), 'suglow'), $path . ': ' . $title);
        }
    }

    // Both used to fall through to the generic site title.
    public function test_most_popular_and_login_have_their_own_titles(): void
    {
        $popular = $this->get('/most-popular')->assertOk()->getContent();
        $this->assertSame('Most Popular Cosmetics & Skincare in Bangladesh | Suglow', html_entity_decode($this->title($popular)));

        $login = $this->get('/login')->assertOk()->getContent();
        $this->assertSame('Log In to Your Account | Suglow', html_entity_decode($this->title($login)));
        // An account page has nothing to rank for.
        $this->assertMatchesRegularExpression('~<meta name="robots" content="noindex~', $login);
    }

    public function test_the_shop_listing_lists_a_brand_by_its_slug(): void
    {
        $cream = $this->product('CeraVe Cream');
        $other = ProductBrand::create(['name' => 'Anua', 'slug' => 'anua', 'status' => Status::ACTIVE]);
        $this->product('Anua Toner', ['product_brand_id' => $other->id]);

        $response = $this->postJson('/api/frontend/product/category-wise-products', ['page' => 1, 'brand_slug' => 'cerave'])->assertOk();

        $this->assertSame([$cream->name], array_column($response->json('data.products'), 'name'));
        $this->assertSame('CeraVe', $response->json('data.brand.name'));
    }

    public function test_an_unknown_brand_slug_lists_nothing_rather_than_everything(): void
    {
        $this->product('CeraVe Cream');

        $response = $this->postJson('/api/frontend/product/category-wise-products', ['page' => 1, 'brand_slug' => 'nobody'])->assertOk();

        $this->assertSame([], $response->json('data.products'));
        $this->assertNull($response->json('data.brand'));
    }

    public function test_the_listing_without_a_brand_still_answers(): void
    {
        $this->product('CeraVe Cream');

        $response = $this->postJson('/api/frontend/product/category-wise-products', ['page' => 1])->assertOk();

        $this->assertCount(1, $response->json('data.products'));
        $this->assertNull($response->json('data.brand'));
    }

    public function test_all_products_links_every_brand_page_for_crawlers(): void
    {
        $this->product('CeraVe Cream');
        ProductBrand::create(['name' => 'Empty Brand', 'slug' => 'empty-brand', 'status' => Status::ACTIVE]);

        $html = $this->get('/product')->assertOk()->getContent();

        $this->assertStringStartsWith('All Products', $this->title($html));
        $this->assertStringContainsString('<link rel="canonical" href="' . url('/product') . '">', $html);
        $text = $this->noscript($html);
        $this->assertStringContainsString('href="' . url('/brand/cerave') . '"', $text);
        $this->assertStringNotContainsString('/brand/empty-brand', $text);
    }

    public function test_a_product_page_links_its_brand_page(): void
    {
        $cream = $this->product('CeraVe Cream');

        $this->assertStringContainsString(
            'href="' . url('/brand/cerave') . '"',
            $this->noscript($this->get('/product/' . $cream->slug)->assertOk()->getContent())
        );
    }

    public function test_brand_pages_are_in_the_sitemap(): void
    {
        $this->product('CeraVe Cream');
        ProductBrand::create(['name' => 'Empty Brand', 'slug' => 'empty-brand', 'status' => Status::ACTIVE]);

        $directory = storage_path('framework/testing/sitemap-' . uniqid());
        mkdir($directory, 0777, true);

        $this->artisan('sitemap:generate', ['--path' => $directory])->assertSuccessful();
        $xml = file_get_contents($directory . '/sitemap.xml');
        @unlink($directory . '/sitemap.xml');
        @rmdir($directory);

        $this->assertStringContainsString('<loc>' . url('/brand/cerave') . '</loc>', $xml);
        $this->assertStringContainsString('<loc>' . url('/offers') . '</loc>', $xml);
        $this->assertStringNotContainsString('empty-brand', $xml, 'a noindex page is not advertised');
    }

    // --- brands:backfill ---------------------------------------------------

    private function unbranded(string $name): Product
    {
        return $this->product($name, ['product_brand_id' => $this->placeholder()->id]);
    }

    private function placeholder(): ProductBrand
    {
        return ProductBrand::firstOrCreate(['slug' => 'no-brand'], ['name' => 'No Brand', 'status' => Status::ACTIVE, 'is_default' => true]);
    }

    public function test_backfill_attaches_products_to_their_brand_by_name(): void
    {
        $mars = ProductBrand::create(['name' => 'MARS', 'slug' => 'mars', 'status' => Status::ACTIVE]);
        $bob = ProductBrand::create(['name' => 'bob', 'slug' => 'bob', 'status' => Status::ACTIVE]);
        $ponds = ProductBrand::create(['name' => 'ponds', 'slug' => 'ponds', 'status' => Status::ACTIVE]);
        $deconstruct = ProductBrand::create(['name' => 'de cons truct', 'slug' => 'de-cons-truct', 'status' => Status::ACTIVE]);
        ProductBrand::create(['name' => 'centella', 'slug' => 'centella', 'status' => Status::ACTIVE]);

        $foundation = $this->unbranded('MARS MATTE MOUSSE FOUNDATION 102#');
        $eyeliner = $this->unbranded('Bob Long Lasting Waterproof Eyeliner');
        $powder = $this->unbranded("POND'S Blurring Filler Translucent Powder");
        $balm = $this->unbranded('Deconstruct Brightening Lip Balm 4.2g');
        $bobbi = $this->unbranded('Bobbi Brown Vitamin Enriched Face Base');
        $toner = $this->unbranded('Madagascar Centella Toning Toner');
        $cerave = $this->unbranded('Hydrating Cleanser by CeraVe 236ml');

        $this->artisan('brands:backfill')->assertSuccessful();

        $brandOf = fn (Product $product) => (int) DB::table('products')->where('id', $product->id)->value('product_brand_id');

        $this->assertSame($mars->id, $brandOf($foundation));
        $this->assertSame($bob->id, $brandOf($eyeliner));
        $this->assertSame($ponds->id, $brandOf($powder), "POND'S is ponds");
        $this->assertSame($deconstruct->id, $brandOf($balm), 'the same letters, spaced differently');
        $this->assertSame($this->placeholder()->id, $brandOf($bobbi), '"bob" must not claim Bobbi Brown');
        $this->assertSame($this->placeholder()->id, $brandOf($toner), 'an ingredient mid-name is not a brand');
        // The curated list may match anywhere; this brand was already there.
        $this->assertSame($this->brand->id, $brandOf($cerave));
    }

    public function test_backfill_dry_run_writes_nothing(): void
    {
        ProductBrand::create(['name' => 'MARS', 'slug' => 'mars', 'status' => Status::ACTIVE]);
        $foundation = $this->unbranded('MARS MATTE MOUSSE FOUNDATION 102#');

        $this->artisan('brands:backfill', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($this->placeholder()->id, (int) DB::table('products')->where('id', $foundation->id)->value('product_brand_id'));
    }
}
