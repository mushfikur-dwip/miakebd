<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Listings put products that have a photo ahead of "No Image Available"
 * tiles, and every product page gets a related-products row.
 */
class CatalogueOrderingTest extends TestCase
{
    use RefreshDatabase;

    private ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = ProductCategory::create(['name' => 'Skin Care', 'slug' => 'skin-care', 'status' => Status::ACTIVE]);
    }

    private function product(string $name, bool $photo): Product
    {
        $product = Product::create([
            'name' => $name, 'slug' => Str::slug($name), 'sku' => strtoupper(Str::random(8)), 'status' => Status::ACTIVE,
            'can_purchasable' => Ask::YES, 'buying_price' => 100, 'selling_price' => 500, 'variation_price' => 500,
            'product_category_id' => $this->category->id,
        ]);

        if ($photo) {
            DB::table('media')->insert([
                'model_type' => $product->getMorphClass(), 'model_id' => $product->id, 'uuid' => (string) Str::uuid(),
                'collection_name' => 'product', 'name' => 'photo', 'file_name' => 'photo.png', 'mime_type' => 'image/png',
                'disk' => 'public', 'conversions_disk' => 'public', 'size' => 100, 'manipulations' => '[]',
                'custom_properties' => '[]', 'generated_conversions' => '[]', 'responsive_images' => '[]',
                'order_column' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $product;
    }

    private function listing(array $body = []): array
    {
        return $this->postJson('/api/frontend/product/category-wise-products', $body + [
            'category' => 'skin-care', 'paginate' => 1, 'page' => 1, 'per_page' => 10,
        ])->assertOk()->json('data.products');
    }

    public function test_the_default_order_shows_products_with_photos_first(): void
    {
        $this->product('Aloe Gel', false);
        $this->product('Banana Cream', true);
        $this->product('Cica Balm', false);
        $this->product('Daisy Toner', true);

        $names = array_column($this->listing(), 'name');

        // Photos first, and A-Z inside each group.
        $this->assertSame(['Banana Cream', 'Daisy Toner', 'Aloe Gel', 'Cica Balm'], $names);
    }

    public function test_an_explicit_sort_is_left_exactly_as_asked(): void
    {
        $this->product('Aloe Gel', false)->update(['variation_price' => 100]);
        $this->product('Banana Cream', true)->update(['variation_price' => 900]);

        $names = array_column($this->listing(['sort_by' => 'price_low_to_high']), 'name');

        $this->assertSame(['Aloe Gel', 'Banana Cream'], $names);
    }

    public function test_an_untagged_product_still_gets_related_products_from_its_category(): void
    {
        $viewed = $this->product('Aloe Gel', true);
        $this->product('Banana Cream', true);
        $this->product('Cica Balm', false);

        $related = $this->getJson('/api/frontend/product/related-products/' . $viewed->slug . '?rand=8')
            ->assertOk()
            ->json('data');

        $this->assertSame('Banana Cream', $related[0]['name']);
        $this->assertCount(2, $related);
        $this->assertNotContains('Aloe Gel', array_column($related, 'name'));
    }
}
