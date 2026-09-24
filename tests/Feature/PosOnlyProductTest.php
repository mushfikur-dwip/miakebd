<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Status;
use App\Http\Requests\PaginateRequest;
use App\Models\Product;
use App\Services\ProductService;
use App\Support\StorefrontOrderGuard;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "POS only": a product stocked in the shop and sellable at the till, but kept
 * off the website entirely.
 *
 * The rule that matters is the default - every product already in the shop, and
 * every new one, is public until someone switches it over.
 */
class PosOnlyProductTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function product(string $name, array $attributes = []): Product
    {
        $n = ++$this->sequence;

        return Product::create($attributes + [
            'name'            => $name,
            'slug'            => Str::slug($name) . '-' . $n,
            'sku'             => 'SKU' . $n,
            'status'          => Status::ACTIVE,
            'can_purchasable' => Ask::YES,
            'buying_price'    => 100,
            'selling_price'   => 150,
            'variation_price' => 150,
        ]);
    }

    private function request(array $query = []): PaginateRequest
    {
        $request = PaginateRequest::create('/api/frontend/product', 'GET', $query + ['paginate' => 0]);
        $request->setContainer(app())->validateResolved();

        return $request;
    }

    private function names($products): array
    {
        return collect($products)->pluck('name')->sort()->values()->all();
    }

    public function test_a_product_is_public_unless_it_is_switched_over(): void
    {
        $product = $this->product('Shampoo');

        $this->assertSame(Ask::NO, (int) $product->fresh()->pos_only);
        $this->assertContains('Shampoo', array_column($this->getJson('/api/frontend/product?paginate=0')->assertOk()->json('data'), 'name'));
    }

    /**
     * The case that matters on the live shop: every product that existed
     * before this column did. They are written without it, so the column
     * default decides - and it must leave them public.
     */
    public function test_a_product_that_predates_the_column_stays_public(): void
    {
        $id = DB::table('products')->insertGetId([
            'name'            => 'Legacy Soap',
            'slug'            => 'legacy-soap',
            'sku'             => 'LEGACY1',
            'status'          => Status::ACTIVE,
            'can_purchasable' => Ask::YES,
            'buying_price'    => 100,
            'selling_price'   => 150,
            'variation_price' => 150,
        ]);

        $this->assertSame(Ask::NO, (int) Product::findOrFail($id)->pos_only);
        $this->assertSame(['Legacy Soap'], $this->names(Product::storefront()->get()));
    }

    public function test_a_pos_only_product_is_not_in_any_public_listing(): void
    {
        $offer = ['offer_start_date' => now()->subDay(), 'offer_end_date' => now()->addDay(), 'discount' => 10];
        $this->product('Public Cream', $offer + ['add_to_flash_sale' => Ask::YES]);
        $this->product('Shop Only Cream', $offer + ['add_to_flash_sale' => Ask::YES, 'pos_only' => Ask::YES]);

        $service = app(ProductService::class);

        $this->assertSame(['Public Cream'], array_column($this->getJson('/api/frontend/product?paginate=0')->assertOk()->json('data'), 'name'), 'storefront list');
        $this->assertSame(['Public Cream'], $this->names($service->mostPopularProducts($this->request())), 'most popular');
        $this->assertSame(['Public Cream'], $this->names($service->flashSaleProducts($this->request())), 'flash sale');
        $this->assertSame(['Public Cream'], $this->names($service->offerProducts($this->request())), 'offers');
    }

    public function test_a_pos_only_product_url_is_not_found(): void
    {
        $public  = $this->product('Public Oil');
        $posOnly = $this->product('Shop Only Oil', ['pos_only' => Ask::YES]);

        $this->getJson('/api/frontend/product/show/' . $public->slug)->assertOk();
        $this->getJson('/api/frontend/product/show/' . $posOnly->slug)->assertNotFound();
        $this->getJson('/api/frontend/product/show-with-trashed/' . $posOnly->slug)->assertNotFound();
    }

    public function test_the_till_and_the_admin_still_see_it(): void
    {
        $this->product('Public Wax');
        $this->product('Shop Only Wax', ['pos_only' => Ask::YES]);

        $service = app(ProductService::class);

        $this->assertSame(['Public Wax', 'Shop Only Wax'], $this->names($service->posList($this->request())), 'the POS grid sells both');
        $this->assertSame(['Public Wax', 'Shop Only Wax'], $this->names($service->list($this->request())), 'the admin product list shows both');
        $this->assertSame(['Public Wax'], $this->names($service->list($this->request(), true)), 'the storefront asks for its own view');
    }

    public function test_an_online_order_containing_a_pos_only_product_is_refused(): void
    {
        $public  = $this->product('Public Balm');
        $posOnly = $this->product('Shop Only Balm', ['pos_only' => Ask::YES]);

        $line = fn(Product $product) => (object) ['product_id' => $product->id, 'variation_id' => 0, 'quantity' => 1];

        StorefrontOrderGuard::assertNoPosOnlyProducts([$line($public)]);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(422);
        StorefrontOrderGuard::assertNoPosOnlyProducts([$line($public), $line($posOnly)]);
    }
}
