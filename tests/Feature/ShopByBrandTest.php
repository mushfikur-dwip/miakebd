<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Product;
use App\Models\ProductBrand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The homepage "Shop by brand" tiles link to /product?brand=<id>, and the shop
 * page posts that id to category-wise-products as a JSON array. The id comes
 * off the URL, so it arrives as a string ("5"), where the sidebar checkboxes
 * send numbers.
 */
class ShopByBrandTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function product(string $name, ?ProductBrand $brand): Product
    {
        $n = ++$this->sequence;

        return Product::create([
            'name'             => $name,
            'slug'             => Str::slug($name) . '-' . $n,
            'sku'              => 'SKU' . $n,
            'status'           => Status::ACTIVE,
            'can_purchasable'  => Ask::YES,
            'buying_price'     => 100,
            'selling_price'    => 150,
            'variation_price'  => 150,
            'product_brand_id' => $brand?->id,
        ]);
    }

    private function shop(array $form)
    {
        // Same shape ProductComponent sends on first load.
        return $this->postJson('/api/frontend/product/category-wise-products', $form + [
            'page'      => 1,
            'status'    => Status::ACTIVE,
            'sort_by'   => null,
            'category'  => null,
            'name'      => null,
            'brand'     => [],
            'variation' => [],
            'min_price' => null,
            'max_price' => null,
        ]);
    }

    public function test_the_shop_lists_products_with_no_filter(): void
    {
        $cerave = ProductBrand::create(['name' => 'CeraVe', 'slug' => 'cerave', 'status' => Status::ACTIVE]);
        $this->product('Moisturising Cream', $cerave);

        $response = $this->shop([])->assertOk();

        $this->assertSame(['Moisturising Cream'], array_column($response->json('data.products'), 'name'));
    }

    public function test_a_brand_tile_link_lists_only_that_brands_products(): void
    {
        $cerave  = ProductBrand::create(['name' => 'CeraVe', 'slug' => 'cerave', 'status' => Status::ACTIVE]);
        $anua    = ProductBrand::create(['name' => 'ANUA', 'slug' => 'anua', 'status' => Status::ACTIVE]);
        $this->product('Moisturising Cream', $cerave);
        $this->product('Heartleaf Toner', $anua);

        $fromUrl = $this->shop(['brand' => json_encode([(string) $cerave->id])])->assertOk();
        $this->assertSame(['Moisturising Cream'], array_column($fromUrl->json('data.products'), 'name'));

        $fromCheckbox = $this->shop(['brand' => json_encode([$anua->id])])->assertOk();
        $this->assertSame(['Heartleaf Toner'], array_column($fromCheckbox->json('data.products'), 'name'));
    }
}
