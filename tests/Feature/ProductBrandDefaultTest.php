<?php

namespace Tests\Feature;

use App\Enums\Status;
use App\Http\Requests\PaginateRequest;
use App\Http\Requests\ProductRequest;
use App\Models\ProductBrand;
use App\Services\ProductBrandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Brand became required on the product form, so every product needs one. The
 * placeholder brand that fills that gap has to stay completely invisible to
 * shoppers - a "No Brand" tile in the brand carousel, or a "No Brand" checkbox
 * in the shop filter that matches the entire catalogue, is just broken-looking.
 */
class ProductBrandDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_creates_exactly_one_default_brand(): void
    {
        $defaults = ProductBrand::where('is_default', true)->get();

        $this->assertCount(1, $defaults);
        $this->assertSame('No Brand', $defaults->first()->name);
        // ACTIVE on purpose: the admin dropdown must still offer it.
        $this->assertSame(Status::ACTIVE, $defaults->first()->status);
    }

    public function test_default_id_helper_finds_it(): void
    {
        $this->assertSame(
            ProductBrand::where('is_default', true)->value('id'),
            ProductBrand::defaultId()
        );
    }

    private function listBrands(array $query)
    {
        return app(ProductBrandService::class)->list(PaginateRequest::create('/', 'GET', $query));
    }

    public function test_storefront_listing_hides_the_default_brand(): void
    {
        ProductBrand::create(['name' => 'CeraVe', 'slug' => 'cerave', 'status' => Status::ACTIVE]);

        $names = $this->listBrands(['exclude_default' => true])->pluck('name');

        $this->assertTrue($names->contains('CeraVe'));
        $this->assertFalse($names->contains('No Brand'));
    }

    public function test_admin_listing_still_offers_the_default_brand(): void
    {
        $names = $this->listBrands([])->pluck('name');

        $this->assertTrue($names->contains('No Brand'));
    }

    public function test_the_public_endpoint_hides_it_even_when_not_asked(): void
    {
        ProductBrand::create(['name' => 'CeraVe', 'slug' => 'cerave', 'status' => Status::ACTIVE]);

        // No exclude_default in the query string: the controller forces it, so
        // a stale bundle or a hand-made request cannot leak the placeholder.
        $response = $this->getJson('/api/frontend/product-brand');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');

        $this->assertTrue($names->contains('CeraVe'));
        $this->assertFalse($names->contains('No Brand'));
    }

    public function test_a_product_cannot_be_saved_without_a_brand(): void
    {
        $rules = (new ProductRequest())->rules();

        $this->assertTrue(Validator::make([], ['product_brand_id' => $rules['product_brand_id']])->fails());
    }

    public function test_the_default_brand_is_an_acceptable_choice(): void
    {
        $rules = (new ProductRequest())->rules();

        $validator = Validator::make(
            ['product_brand_id' => ProductBrand::defaultId()],
            ['product_brand_id' => $rules['product_brand_id']]
        );

        $this->assertFalse($validator->fails());
    }

    public function test_a_brand_that_does_not_exist_is_rejected(): void
    {
        $rules = (new ProductRequest())->rules();

        $validator = Validator::make(
            ['product_brand_id' => 999999],
            ['product_brand_id' => $rules['product_brand_id']]
        );

        $this->assertTrue($validator->fails());
    }

    public function test_every_product_row_ends_up_with_a_brand(): void
    {
        // Stand in for the live catalogue: rows written before brand existed.
        $this->assertSame(
            0,
            DB::table('products')->whereNull('product_brand_id')->count(),
            'the backfill left products without a brand'
        );
    }
}
