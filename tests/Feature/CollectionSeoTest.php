<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Status;
use App\Http\Requests\ProductBrandRequest;
use App\Http\Requests\ProductCategoryRequest;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\Stock;
use App\Services\ProductBrandService;
use App\Services\ProductCategoryService;
use Dipokhalder\Settings\Facades\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * Brand and category pages as search engines and answer engines read them:
 * a price range in the description, an intro, the questions shoppers ask with
 * real answers, links to the related pages, and the admin's own words when
 * the admin has written some.
 */
class CollectionSeoTest extends TestCase
{
    use RefreshDatabase;

    private ProductBrand $cerave;
    private ProductCategory $skinCare;
    private ProductCategory $sunscreen;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::group('company')->set(['company_name' => 'SUGLOW']);

        $this->cerave    = ProductBrand::create(['name' => 'CeraVe', 'slug' => 'cerave', 'status' => Status::ACTIVE]);
        $this->skinCare  = ProductCategory::create(['name' => 'Skin Care', 'slug' => 'skin-care', 'status' => Status::ACTIVE]);
        $this->sunscreen = ProductCategory::create(['name' => 'Sunscreen', 'slug' => 'sunscreen', 'status' => Status::ACTIVE, 'parent_id' => $this->skinCare->id]);
    }

    private function product(string $name, float $price, array $attributes = []): Product
    {
        $n = ++$this->sequence;

        return Product::create($attributes + [
            'name'                => $name,
            'slug'                => 'product-' . $n,
            'sku'                 => 'SKU' . $n,
            'status'              => Status::ACTIVE,
            'can_purchasable'     => Ask::YES,
            'buying_price'        => 100,
            'selling_price'       => $price,
            'variation_price'     => $price,
            'product_brand_id'    => $this->cerave->id,
            'product_category_id' => $this->sunscreen->id,
        ]);
    }

    /** Units sold, recorded the way a sale records them: a negative stock row. */
    private function sell(Product $product, int $units): void
    {
        Stock::create([
            'product_id' => $product->id,
            'model_type' => Order::class,
            'model_id'   => 1,
            'item_type'  => Product::class,
            'item_id'    => $product->id,
            'quantity'   => -$units,
            'status'     => Status::ACTIVE,
        ]);
    }

    private function description(string $html): string
    {
        preg_match('/<meta name="description" content="([^"]*)">/', $html, $match);

        return html_entity_decode($match[1] ?? '', ENT_QUOTES | ENT_HTML5);
    }

    private function title(string $html): string
    {
        preg_match('/<title>([^<]*)<\/title>/', $html, $match);

        return html_entity_decode($match[1] ?? '', ENT_QUOTES | ENT_HTML5);
    }

    private function noscript(string $html): string
    {
        preg_match('/<noscript>(.*?)<\/noscript>/s', explode('<body>', $html)[1] ?? '', $match);

        return html_entity_decode($match[1] ?? '', ENT_QUOTES | ENT_HTML5);
    }

    /** Every node of every JSON-LD block on the page. */
    private function graph(string $html): array
    {
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);

        $nodes = [];
        foreach ($matches[1] as $json) {
            $data  = json_decode($json, true);
            $nodes = array_merge($nodes, $data['@graph'] ?? [$data]);
        }

        return $nodes;
    }

    private function node(string $html, string $type): ?array
    {
        foreach ($this->graph($html) as $node) {
            if (($node['@type'] ?? null) === $type) {
                return $node;
            }
        }

        return null;
    }

    /** question => answer, from the page's FAQPage node. */
    private function faqs(string $html): array
    {
        $faq = $this->node($html, 'FAQPage');

        $this->assertNotNull($faq, 'the page carries an FAQPage');

        $pairs = [];
        foreach ($faq['mainEntity'] as $question) {
            $pairs[$question['name']] = $question['acceptedAnswer']['text'];
        }

        return $pairs;
    }

    private function sitemap(): string
    {
        $directory = storage_path('framework/testing/sitemap-' . uniqid());
        mkdir($directory, 0777, true);

        $this->artisan('sitemap:generate', ['--path' => $directory])->assertSuccessful();
        $xml = file_get_contents($directory . '/sitemap.xml');
        @unlink($directory . '/sitemap.xml');
        @rmdir($directory);

        return $xml;
    }

    // --- brand pages ---------------------------------------------------------

    public function test_a_brand_description_quotes_its_price_range(): void
    {
        $this->product('CeraVe SA Smoothing Cleanser 88ml', 1350);
        $this->product('CeraVe Moisturising Cream 454g', 5430);
        $this->product('CeraVe Hydrating Sunscreen 75ml', 2850);

        $description = $this->description($this->get('/brand/cerave')->assertOk()->getContent());

        $this->assertStringStartsWith('CeraVe price in Bangladesh: ৳1,350–৳5,430.', $description);
        $this->assertStringContainsString('3 authentic CeraVe products', $description);
        $this->assertLessThanOrEqual(160, mb_strlen($description));
    }

    public function test_a_one_product_brand_reads_naturally(): void
    {
        $this->product('CeraVe SA Smoothing Cleanser 88ml', 420);

        $description = $this->description($this->get('/brand/cerave')->assertOk()->getContent());

        $this->assertStringStartsWith('CeraVe price in Bangladesh: ৳420.', $description);
        $this->assertStringNotContainsString('1 authentic', $description);
        $this->assertStringNotContainsString('1 products', $description);
    }

    public function test_a_brand_page_answers_the_questions_shoppers_ask(): void
    {
        $this->product('CeraVe SA Smoothing Cleanser 88ml', 1350);
        $this->product('CeraVe Moisturising Cream 454g', 5430);

        $html = $this->get('/brand/cerave')->assertOk()->getContent();
        $faqs = $this->faqs($html);

        $this->assertArrayHasKey('What is the price of CeraVe in Bangladesh?', $faqs);
        $this->assertStringContainsString('৳1,350', $faqs['What is the price of CeraVe in Bangladesh?']);
        $this->assertStringContainsString('৳5,430', $faqs['What is the price of CeraVe in Bangladesh?']);
        $this->assertArrayHasKey('Is CeraVe at Suglow original?', $faqs);
        $this->assertArrayHasKey('Can I buy CeraVe with cash on delivery in Bangladesh?', $faqs);
        $this->assertArrayHasKey('Where can I buy CeraVe in Rangpur?', $faqs);

        // What the markup says must be on the page itself.
        $text = $this->noscript($html);
        $this->assertStringContainsString('About CeraVe in Bangladesh', $text);
        $this->assertStringContainsString('What is the price of CeraVe in Bangladesh?', $text);
        $this->assertStringContainsString($faqs['Is CeraVe at Suglow original?'], $text);
    }

    public function test_best_sellers_are_named_only_from_real_sales(): void
    {
        $cleanser = $this->product('CeraVe SA Smoothing Cleanser 88ml', 1350);
        $cream    = $this->product('CeraVe Moisturising Cream 454g', 5430);

        $question = 'Which CeraVe products sell best at Suglow?';
        $this->assertArrayNotHasKey($question, $this->faqs($this->get('/brand/cerave')->getContent()));

        $this->sell($cleanser, 2);
        $this->sell($cream, 9);
        \Illuminate\Support\Facades\Cache::flush();

        $answer = $this->faqs($this->get('/brand/cerave')->getContent())[$question] ?? '';
        $this->assertStringContainsString('CeraVe Moisturising Cream 454g', $answer);
        $this->assertLessThan(
            mb_strpos($answer, 'CeraVe SA Smoothing Cleanser'),
            mb_strpos($answer, 'CeraVe Moisturising Cream'),
            'the bigger seller is named first'
        );
    }

    public function test_a_brand_page_links_the_categories_it_is_sold_in(): void
    {
        $this->product('CeraVe Hydrating Sunscreen 75ml', 2850);

        $text = $this->noscript($this->get('/brand/cerave')->assertOk()->getContent());

        $this->assertStringContainsString('href="' . url('/product-category/sunscreen') . '"', $text);
    }

    public function test_the_brand_node_describes_the_brand(): void
    {
        $this->product('CeraVe Hydrating Sunscreen 75ml', 2850);

        $brand = $this->node($this->get('/brand/cerave')->assertOk()->getContent(), 'Brand');

        $this->assertSame(url('/brand/cerave'), $brand['url']);
        $this->assertNotEmpty($brand['description']);
    }

    // --- category pages ------------------------------------------------------

    public function test_a_category_page_names_its_brands(): void
    {
        $anua = ProductBrand::create(['name' => 'Anua', 'slug' => 'anua', 'status' => Status::ACTIVE]);
        $this->product('CeraVe Hydrating Sunscreen 75ml', 2850);
        $this->product('Anua Heartleaf Sun Cream', 1650, ['product_brand_id' => $anua->id]);

        $html = $this->get('/product-category/sunscreen')->assertOk()->getContent();
        $faqs = $this->faqs($html);

        $this->assertStringStartsWith('Sunscreen price in Bangladesh: ৳1,650–৳2,850.', $this->description($html));
        $this->assertArrayHasKey('Which Sunscreen brands does Suglow sell?', $faqs);
        $this->assertStringContainsString('CeraVe', $faqs['Which Sunscreen brands does Suglow sell?']);
        $this->assertStringContainsString('Anua', $faqs['Which Sunscreen brands does Suglow sell?']);

        $text = $this->noscript($html);
        $this->assertStringContainsString('href="' . url('/brand/cerave') . '"', $text);
        $this->assertStringContainsString('href="' . url('/brand/anua') . '"', $text);
    }

    public function test_a_category_breadcrumb_includes_its_parent(): void
    {
        $this->product('CeraVe Hydrating Sunscreen 75ml', 2850);

        $breadcrumb = $this->node($this->get('/product-category/sunscreen')->assertOk()->getContent(), 'BreadcrumbList');

        $this->assertSame(['Home', 'Skin Care', 'Sunscreen'], array_column($breadcrumb['itemListElement'], 'name'));
        $this->assertSame(url('/product-category/skin-care'), $breadcrumb['itemListElement'][1]['item']);
    }

    public function test_an_empty_category_is_kept_out_of_the_index_and_the_sitemap(): void
    {
        ProductCategory::create(['name' => 'Perfume', 'slug' => 'perfume', 'status' => Status::ACTIVE]);
        $this->product('CeraVe Hydrating Sunscreen 75ml', 2850);

        $this->assertStringContainsString(
            'content="noindex, follow"',
            $this->get('/product-category/perfume')->assertOk()->getContent()
        );

        $xml = $this->sitemap();
        $this->assertStringNotContainsString('/product-category/perfume', $xml);
        $this->assertStringContainsString('<loc>' . url('/product-category/sunscreen') . '</loc>', $xml);
    }

    public function test_the_redirecting_all_product_category_is_not_in_the_sitemap(): void
    {
        $all = ProductCategory::create(['name' => 'All Product', 'slug' => 'all-product', 'status' => Status::ACTIVE]);
        $this->product('CeraVe Hydrating Sunscreen 75ml', 2850, ['product_category_id' => $all->id]);

        $this->assertStringNotContainsString('/product-category/all-product', $this->sitemap());
    }

    // --- the admin's own words -----------------------------------------------

    public function test_admin_seo_fields_override_the_generated_text(): void
    {
        $this->product('CeraVe Hydrating Sunscreen 75ml', 2850);
        $this->cerave->update([
            'seo_title'       => 'CeraVe Bangladesh — Original Cleansers & Creams',
            'seo_description' => 'Original CeraVe in Bangladesh, from our own import.',
            'seo_content'     => "First paragraph about CeraVe.\n\nSecond paragraph about CeraVe.",
        ]);

        $html = $this->get('/brand/cerave')->assertOk()->getContent();

        $this->assertStringStartsWith('CeraVe Bangladesh — Original Cleansers & Creams', $this->title($html));
        $this->assertSame('Original CeraVe in Bangladesh, from our own import.', $this->description($html));
        $text = $this->noscript($html);
        $this->assertStringContainsString('First paragraph about CeraVe.', $text);
        $this->assertStringContainsString('Second paragraph about CeraVe.', $text);

        $this->sunscreen->update(['seo_title' => 'Best Sunscreen in Bangladesh', 'seo_content' => 'Sunscreen advice.']);
        $category = $this->get('/product-category/sunscreen')->assertOk()->getContent();
        $this->assertStringStartsWith('Best Sunscreen in Bangladesh', $this->title($category));
        $this->assertStringContainsString('Sunscreen advice.', $this->noscript($category));
    }

    /** The admin form's request, bound to the record being edited. */
    private function adminRequest(string $class, string $parameter, array $data, $editing): mixed
    {
        $request = $class::create('/', 'POST', $data + ['status' => Status::ACTIVE]);

        $route = new Route('POST', 'admin/setting/x/{' . $parameter . '?}', []);
        $route->bind($request);
        $route->setParameter($parameter, $editing);
        $request->setRouteResolver(fn () => $route);

        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();

        return $request;
    }

    public function test_the_admin_forms_save_the_seo_fields(): void
    {
        $fields = ['seo_title' => 'A title', 'seo_description' => 'A description', 'seo_content' => 'Some words.'];

        $brandRequest = $this->adminRequest(ProductBrandRequest::class, 'productBrand', ['name' => 'CeraVe'] + $fields, $this->cerave);
        app(ProductBrandService::class)->update($brandRequest, $this->cerave);

        $categoryRequest = $this->adminRequest(ProductCategoryRequest::class, 'productCategory', ['name' => 'Skin Care', 'parent_id' => 'NULL'] + $fields, $this->skinCare);
        app(ProductCategoryService::class)->update($categoryRequest, $this->skinCare);

        foreach ([$this->cerave->fresh(), $this->skinCare->fresh()] as $record) {
            $this->assertSame('A title', $record->seo_title);
            $this->assertSame('A description', $record->seo_description);
            $this->assertSame('Some words.', $record->seo_content);
        }
    }

    /** The edit dialogs fill from the admin list; the shop's brand carousel has no use for them. */
    public function test_only_the_admin_lists_carry_the_seo_fields(): void
    {
        $this->cerave->update(['seo_title' => 'A title', 'seo_description' => 'A description', 'seo_content' => 'Some words.']);
        $this->skinCare->update(['seo_title' => 'A title', 'seo_description' => 'A description', 'seo_content' => 'Some words.']);

        $admin = \Illuminate\Http\Request::create('/api/admin/setting/product-brand');
        $shop  = \Illuminate\Http\Request::create('/api/frontend/product-brand');

        $brand = (new \App\Http\Resources\ProductBrandResource($this->cerave->fresh()))->resolve($admin);
        $this->assertSame(['A title', 'A description', 'Some words.'], [$brand['seo_title'], $brand['seo_description'], $brand['seo_content']]);
        $this->assertArrayNotHasKey('seo_content', (new \App\Http\Resources\ProductBrandResource($this->cerave->fresh()))->resolve($shop));

        $category = (new \App\Http\Resources\ProductCategoryResource($this->skinCare->fresh()))->resolve($admin);
        $this->assertSame('Some words.', $category['seo_content']);
        $this->assertArrayNotHasKey('seo_content', (new \App\Http\Resources\ProductCategoryResource($this->skinCare->fresh()))->resolve(\Illuminate\Http\Request::create('/api/frontend/product-category')));
    }

    // --- brand renames -------------------------------------------------------

    public function test_saving_a_brand_without_renaming_it_keeps_its_address(): void
    {
        $wishcare = ProductBrand::create(['name' => 'Wish Care', 'slug' => 'wishcare', 'status' => Status::ACTIVE]);

        $request = $this->adminRequest(ProductBrandRequest::class, 'productBrand', ['name' => 'Wish Care', 'description' => 'Indian skincare'], $wishcare);
        app(ProductBrandService::class)->update($request, $wishcare);

        $this->assertSame('wishcare', $wishcare->fresh()->slug);
    }

    public function test_a_renamed_brand_keeps_its_old_address(): void
    {
        $himaloya = ProductBrand::create(['name' => 'HIMALOYA', 'slug' => 'himaloya', 'status' => Status::ACTIVE]);
        $this->product('Himalaya Purifying Neem Face Wash', 420, ['product_brand_id' => $himaloya->id]);

        $request = $this->adminRequest(ProductBrandRequest::class, 'productBrand', ['name' => 'Himalaya'], $himaloya);
        app(ProductBrandService::class)->update($request, $himaloya);

        $this->assertSame('himalaya', $himaloya->fresh()->slug);
        $this->get('/brand/himaloya')->assertStatus(301)->assertRedirect(url('/brand/himalaya'));
        $this->get('/brand/himalaya')->assertOk();
    }

    // --- the shop page's copy of it ------------------------------------------

    public function test_the_collection_api_serves_the_same_content(): void
    {
        $this->product('CeraVe SA Smoothing Cleanser 88ml', 1350);
        $this->product('CeraVe Moisturising Cream 454g', 5430);

        $page = $this->get('/brand/cerave')->getContent();
        $api  = $this->getJson('/api/frontend/collection-seo/brand/cerave')->assertOk();

        $this->assertSame('About CeraVe in Bangladesh', $api->json('data.heading'));
        $this->assertSame($this->description($page), $api->json('data.description'));
        $this->assertSame(array_keys($this->faqs($page)), array_column($api->json('data.faqs'), 'question'));
        $this->assertNotEmpty($api->json('data.intro'));

        $this->getJson('/api/frontend/collection-seo/category/sunscreen')->assertOk()
            ->assertJsonPath('data.heading', 'About Sunscreen in Bangladesh');
        $this->getJson('/api/frontend/collection-seo/brand/nobody')->assertNotFound();
        $this->getJson('/api/frontend/collection-seo/shelf/cerave')->assertNotFound();
    }

    // --- site-wide lists -----------------------------------------------------

    public function test_llms_txt_lists_the_live_categories_and_brands(): void
    {
        $this->product('CeraVe Hydrating Sunscreen 75ml', 2850);

        $directory = storage_path('framework/testing/llms-' . uniqid());
        mkdir($directory, 0777, true);

        $this->artisan('seo:llms', ['--path' => $directory])->assertSuccessful();
        $text = file_get_contents($directory . '/llms.txt');
        @unlink($directory . '/llms.txt');
        @rmdir($directory);

        $this->assertStringStartsWith('# Suglow', $text);
        $this->assertStringContainsString('(' . url('/product-category/sunscreen') . ')', $text);
        $this->assertStringContainsString('(' . url('/brand/cerave') . ')', $text);
        $this->assertStringContainsString('৳2,850', $text);
        $this->assertStringNotContainsString('men-skin-care', $text);
        $this->assertStringNotContainsString('skin-care6', $text);
    }

    public function test_the_crawler_fallback_links_only_live_categories(): void
    {
        $this->product('CeraVe Hydrating Sunscreen 75ml', 2850);

        $text = $this->noscript($this->get('/')->assertOk()->getContent());

        $this->assertStringContainsString('href="' . url('/product-category/sunscreen') . '"', $text);
        $this->assertStringNotContainsString('men-skin-care', $text);
        $this->assertStringNotContainsString('skin-care6', $text);
    }
}
