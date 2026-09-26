<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What Google, Merchant Center, Meta's catalogue and AI crawlers read about a
 * product - all from the raw HTML or the feed, none of it needing JavaScript.
 */
class ProductSeoTest extends TestCase
{
    use RefreshDatabase;

    private ProductCategory $category;
    private ProductBrand $brand;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Stored exactly as the live data has it: a control character in front.
        $this->category = ProductCategory::create(['name' => "\x1DSkin Care", 'slug' => 'skin-care', 'status' => Status::ACTIVE]);
        $this->brand    = ProductBrand::create(['name' => 'Care:Nel', 'slug' => 'carenel', 'status' => Status::ACTIVE]);
    }

    private function product(string $name, array $attributes = []): Product
    {
        $n = ++$this->sequence;

        return Product::create($attributes + [
            'name'                => $name,
            'slug'                => 'product-' . $n,
            'sku'                 => 'SKU' . $n,
            'status'              => Status::ACTIVE,
            'can_purchasable'     => Ask::YES,
            'buying_price'        => 800,
            'selling_price'       => 1220,
            'variation_price'     => 1220,
            'product_category_id' => $this->category->id,
            'product_brand_id'    => $this->brand->id,
        ]);
    }

    private function schema(string $html): array
    {
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);

        foreach ($matches[1] as $json) {
            foreach (json_decode($json, true)['@graph'] ?? [] as $node) {
                if (($node['@type'] ?? null) === 'Product') {
                    return $node;
                }
            }
        }

        $this->fail('no Product markup on the page');
    }

    private function noscript(string $html): string
    {
        preg_match('/<noscript>(.*?)<\/noscript>/s', explode('<body>', $html)[1] ?? '', $match);

        return $match[1] ?? '';
    }

    // --- the product page --------------------------------------------------

    /** Its only description used to be its own name, repeated. */
    public function test_a_product_without_a_description_gets_a_real_one(): void
    {
        $product = $this->product('Care:Nel Dr. skin  Cicavita B5 Cleanser (150ml)');

        $html = $this->get('/product/' . $product->slug)->assertOk()->getContent();

        $this->assertStringContainsString('<title>Care:Nel Dr. skin Cicavita B5 Cleanser (150ml) Price in Bangladesh', $html);
        preg_match('/<meta name="description" content="([^"]*)"/', $html, $description);
        $text = html_entity_decode($description[1]);
        $this->assertStringContainsString('Buy Care:Nel Dr. skin Cicavita B5 Cleanser (150ml) in Bangladesh at ৳1,220', $text);
        $this->assertStringContainsString('100% authentic Care:Nel skin care', $text);
        $this->assertSame($text, $this->schema($html)['description']);
    }

    public function test_a_written_description_is_kept(): void
    {
        $product = $this->product('Serum', ['description' => '<p>A lightweight niacinamide serum that fades dark spots and evens skin tone.</p>']);

        $html = $this->get('/product/' . $product->slug)->assertOk()->getContent();

        $this->assertStringContainsString('A lightweight niacinamide serum that fades dark spots', $this->schema($html)['description']);
    }

    public function test_the_markup_is_clean_and_complete(): void
    {
        $product = $this->product('Cleanser');

        $schema = $this->schema($this->get('/product/' . $product->slug)->assertOk()->getContent());

        $this->assertSame('Skin Care', $schema['category'], 'no control character');
        $this->assertSame(['@type' => 'Brand', 'name' => 'Care:Nel'], $schema['brand']);
        $this->assertSame('https://schema.org/NewCondition', $schema['offers']['itemCondition']);
        $this->assertStringEndsWith('/#organization', $schema['offers']['seller']['@id']);
        // Not on offer: no expiry date is invented for the regular price.
        $this->assertArrayNotHasKey('priceValidUntil', $schema['offers']);
        $this->assertArrayNotHasKey('priceSpecification', $schema['offers']);
    }

    public function test_an_offer_shows_its_end_date_and_the_regular_price(): void
    {
        $product = $this->product('Cleanser', [
            'discount' => 10, 'offer_start_date' => now()->subDay(), 'offer_end_date' => now()->addDays(5),
        ]);

        $offers = $this->schema($this->get('/product/' . $product->slug)->assertOk()->getContent())['offers'];

        $this->assertSame('1098.00', $offers['price']);
        $this->assertSame(now()->addDays(5)->toDateString(), $offers['priceValidUntil']);
        $this->assertSame('1220.00', $offers['priceSpecification']['price']);
        $this->assertSame('https://schema.org/StrikethroughPrice', $offers['priceSpecification']['priceType']);
    }

    public function test_the_placeholder_brand_is_never_published(): void
    {
        $placeholder = ProductBrand::create(['name' => 'No Brand', 'slug' => 'no-brand', 'status' => Status::ACTIVE, 'is_default' => true]);
        $product = $this->product('Cleanser', ['product_brand_id' => $placeholder->id]);

        $html = $this->get('/product/' . $product->slug)->assertOk()->getContent();

        $this->assertArrayNotHasKey('brand', $this->schema($html));
        $this->assertStringNotContainsString('No Brand', $this->noscript($html));
    }

    /** What an AI crawler, which never runs JavaScript, can read and follow. */
    public function test_crawlers_read_the_product_and_find_its_neighbours(): void
    {
        $product = $this->product('Cicavita Cleanser', ['description' => "<p>A gentle salicylic acid cleanser for oily skin.</p><p>Q: Is it suitable for daily use? A: Yes, twice a day.</p>"]);
        $neighbour = $this->product('Cica Toner');
        $tillOnly = $this->product('Shop Only Toner', ['pos_only' => Ask::YES]);

        $text = $this->noscript($this->get('/product/' . $product->slug)->assertOk()->getContent());

        $this->assertStringContainsString('<h1>Cicavita Cleanser</h1>', $text);
        $this->assertStringContainsString('Price in Bangladesh: ৳1,220', $text);
        $this->assertStringContainsString('Care:Nel', $text);
        $this->assertStringContainsString('Q: Is it suitable for daily use? A: Yes, twice a day.', $text, 'every block, not just the first');
        $this->assertStringContainsString('/product/' . $neighbour->slug, $text);
        $this->assertStringNotContainsString('/product/' . $tillOnly->slug, $text, 'a POS-only product 404s on the website');
    }

    public function test_a_category_page_links_its_products_for_crawlers(): void
    {
        $listed = $this->product('Cica Toner');
        $tillOnly = $this->product('Shop Only Toner', ['pos_only' => Ask::YES]);

        $html = $this->get('/product-category/skin-care')->assertOk()->getContent();
        $text = $this->noscript($html);

        $this->assertStringContainsString('/product/' . $listed->slug, $text);
        $this->assertStringNotContainsString('/product/' . $tillOnly->slug, $html, 'not in the links, not in the ItemList');
    }

    // --- the catalogue feed ------------------------------------------------

    private function feed(): \SimpleXMLElement
    {
        $path = storage_path('framework/testing/products-feed.xml');
        @unlink($path);

        $this->artisan('feeds:products', ['--path' => $path])->assertSuccessful();

        $xml = simplexml_load_file($path);
        @unlink($path);

        return $xml;
    }

    private function withPhoto(Product $product): Product
    {
        $product->addMedia(UploadedFile::fake()->image('photo.jpg', 800, 800))->toMediaCollection('product');

        return $product;
    }

    /** @return array<string,\SimpleXMLElement> keyed by g:id */
    private function items(\SimpleXMLElement $xml): array
    {
        $items = [];
        foreach ($xml->channel->item as $item) {
            $items[(string) $item->children('g', true)->id] = $item->children('g', true);
        }

        return $items;
    }

    public function test_a_product_whose_photo_file_is_gone_is_left_out_of_the_feed(): void
    {
        Storage::fake('public');

        $kept = $this->withPhoto($this->product('Toner'));
        $lost = $this->withPhoto($this->product('Primer'));
        // The media row survives, its files do not - original and resized
        // copies alike, as for uploads 1685-1727.
        \Illuminate\Support\Facades\File::deleteDirectory(dirname($lost->getFirstMedia('product')->getPath()));

        $items = $this->items($this->feed());

        $this->assertArrayHasKey((string) $kept->id, $items);
        $this->assertArrayNotHasKey((string) $lost->id, $items);
    }

    public function test_the_feed_lists_sellable_products_with_what_both_platforms_need(): void
    {
        Storage::fake('public');

        // 8809915630003 is a real EAN-13; SKU5 is not a barcode at all.
        $withBarcode = $this->withPhoto($this->product('Cleanser', ['sku' => '8809915630003', 'discount' => 10, 'offer_start_date' => now()->subDay(), 'offer_end_date' => now()->addDay()]));
        $noBarcode = $this->withPhoto($this->product('Toner'));
        $noPhoto = $this->product('Mask');
        $tillOnly = $this->withPhoto($this->product('Shop Only Serum', ['pos_only' => Ask::YES]));

        $items = $this->items($this->feed());

        $this->assertSame([(string) $withBarcode->id, (string) $noBarcode->id], array_map('strval', array_keys($items)), 'no photo and POS-only are left out');

        $item = $items[(string) $withBarcode->id];
        $this->assertSame('Cleanser', (string) $item->title);
        $this->assertSame('1220.00 BDT', (string) $item->price);
        $this->assertSame('1098.00 BDT', (string) $item->sale_price);
        $this->assertSame('8809915630003', (string) $item->gtin);
        $this->assertSame('Care:Nel', (string) $item->brand);
        $this->assertSame('new', (string) $item->condition);
        $this->assertSame('Skin Care', (string) $item->product_type);
        $this->assertStringEndsWith('/product/' . $withBarcode->slug, (string) $item->link);
        $this->assertStringStartsWith('http', (string) $item->image_link);
        $this->assertStringContainsString('Buy Cleanser in Bangladesh', (string) $item->description);

        // No barcode: said outright, which is what stops "missing GTIN" warnings.
        $this->assertSame('', (string) $items[(string) $noBarcode->id]->gtin);
        $this->assertSame('no', (string) $items[(string) $noBarcode->id]->identifier_exists);
    }

    public function test_feed_ids_match_what_the_pixel_sends(): void
    {
        Storage::fake('public');
        config(['services.meta_pixel.content_id' => 'sku']);

        $this->withPhoto($this->product('Toner', ['sku' => 'TONER-1']));

        $this->assertSame(['TONER-1'], array_keys($this->items($this->feed())));
    }
}
