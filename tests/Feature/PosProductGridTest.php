<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Status;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\PosProductResource;
use App\Libraries\AppLibrary;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeOption;
use App\Models\ProductReview;
use App\Models\ProductVariation;
use App\Models\Stock;
use App\Models\User;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The POS asks for the whole catalogue in one request, so what the grid costs
 * per product is what the page costs to open. It used to be two to three
 * queries per product. These pin that it now stays flat as the catalogue
 * grows, and that the leaner tile still carries the right branch stock, price
 * and search results.
 */
class PosProductGridTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function product(string $name, array $attributes = []): Product
    {
        return Product::create($attributes + [
            'name'            => $name,
            'slug'            => Str::slug($name) . '-' . ++$this->sequence,
            'sku'             => 'SKU' . $this->sequence,
            'status'          => Status::ACTIVE,
            'can_purchasable' => Ask::YES,
            'buying_price'    => 100,
            'selling_price'   => 150,
            'variation_price' => 150,
        ]);
    }

    protected function outlet(string $name): Outlet
    {
        return Outlet::create([
            'name'     => $name,
            'email'    => Str::slug($name) . '@example.test',
            'phone'    => '01700000000',
            'city'     => 'Dhaka',
            'state'    => 'Dhaka',
            'zip_code' => '1212',
            'address'  => $name . ', Dhaka',
            'status'   => Status::ACTIVE,
        ]);
    }

    protected function stock(Product $product, ?Outlet $outlet, int $quantity, string $source = Product::class): void
    {
        Stock::create([
            'product_id' => $product->id,
            'outlet_id'  => $outlet?->id,
            'model_type' => $source,
            'model_id'   => $product->id,
            'item_type'  => Product::class,
            'item_id'    => $product->id,
            'quantity'   => $quantity,
            'status'     => Status::ACTIVE,
        ]);
    }

    protected function variation(Product $product): void
    {
        $attribute = ProductAttribute::forceCreate(['name' => 'Size']);
        $option    = ProductAttributeOption::forceCreate(['product_attribute_id' => $attribute->id, 'name' => 'Large']);

        ProductVariation::forceCreate([
            'product_id'                  => $product->id,
            'product_attribute_id'        => $attribute->id,
            'product_attribute_option_id' => $option->id,
            'price'                       => 180,
        ]);
    }

    /**
     * Shaped like the live catalogue: every product has an image, reviews, a
     * sales history and stock, and every other one has variations - each of
     * which used to cost the grid a query of its own.
     */
    protected function catalogue(int $count, Outlet $outlet): void
    {
        $reviewer = User::create([
            'name'     => 'Reviewer',
            'username' => 'reviewer' . ++$this->sequence,
            'email'    => 'reviewer' . $this->sequence . '@example.test',
            'password' => bcrypt('secret'),
            'status'   => Status::ACTIVE,
        ]);

        for ($i = 0; $i < $count; $i++) {
            $product = $this->product('Product ' . $this->sequence);

            DB::table('media')->insert([
                'model_type'            => $product->getMorphClass(),
                'model_id'              => $product->id,
                'uuid'                  => (string) Str::uuid(),
                'collection_name'       => 'product',
                'name'                  => 'photo',
                'file_name'             => 'photo.jpg',
                'mime_type'             => 'image/jpeg',
                'disk'                  => 'public',
                'conversions_disk'      => 'public',
                'size'                  => 1,
                'manipulations'         => '[]',
                'custom_properties'     => '[]',
                'generated_conversions' => '{"cover":true}',
                'responsive_images'     => '[]',
                'order_column'          => 1,
            ]);

            ProductReview::create(['user_id' => $reviewer->id, 'product_id' => $product->id, 'star' => 5, 'review' => 'Good']);
            ProductReview::create(['user_id' => $reviewer->id, 'product_id' => $product->id, 'star' => 4, 'review' => 'Fine']);

            $this->stock($product, $outlet, 20);
            $this->stock($product, $outlet, -2, Order::class);
            $this->stock($product, $outlet, -1, Order::class);

            if ($i % 2 === 0) {
                $this->variation($product);
            }
        }
    }

    protected function request(array $query = []): PaginateRequest
    {
        $request = PaginateRequest::create('/api/admin/product/pos-products', 'GET', $query + ['paginate' => 0]);
        $request->setContainer(app())->validateResolved();

        return $request;
    }

    protected function tiles(PaginateRequest $request): array
    {
        return PosProductResource::collection(app(ProductService::class)->posList($request))->resolve();
    }

    protected function grid(array $query = []): array
    {
        return $this->tiles($this->request($query));
    }

    protected function queriesFor(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_grid_costs_the_same_queries_for_forty_products_as_for_five(): void
    {
        $outlet = $this->outlet('Main');

        $this->catalogue(5, $outlet);
        $request = $this->request(['outlet_id' => $outlet->id]);
        $small   = $this->queriesFor(fn() => $this->tiles($request));

        $this->catalogue(35, $outlet);
        $request = $this->request(['outlet_id' => $outlet->id]);
        $large   = $this->queriesFor(fn() => $this->tiles($request));

        $this->assertCount(40, $this->grid(['outlet_id' => $outlet->id]));
        $this->assertSame($small, $large);
        $this->assertSame(2, $large, 'products, then their media');
    }

    public function test_each_tile_shows_the_stock_of_the_branch_the_till_is_booking_against(): void
    {
        $main = $this->outlet('Main');
        $mall = $this->outlet('Mall');
        $soap = $this->product('Soap');

        $this->stock($soap, $main, 7);
        $this->stock($soap, $mall, 3);

        $this->assertSame(7, $this->grid(['outlet_id' => $main->id])[0]['stock']);
        $this->assertSame(3, $this->grid(['outlet_id' => $mall->id])[0]['stock']);
        $this->assertSame(10, $this->grid()[0]['stock']);
    }

    public function test_a_product_with_variations_is_priced_from_its_variations(): void
    {
        $this->product('Plain', ['selling_price' => 150, 'variation_price' => 999]);
        $this->variation($this->product('Varied', ['selling_price' => 150, 'variation_price' => 180]));

        $tiles = collect($this->grid())->keyBy('name');

        $this->assertSame(AppLibrary::currencyAmountFormat(150), $tiles['Plain']['currency_price']);
        $this->assertSame(AppLibrary::currencyAmountFormat(180), $tiles['Varied']['currency_price']);
    }

    public function test_search_narrows_the_grid(): void
    {
        $this->product('Rose Soap');
        $this->product('Aloe Gel');

        $this->assertSame(['Aloe Gel'], array_column($this->grid(['name' => 'aloe']), 'name'));
    }

    public function test_a_tile_carries_only_what_the_grid_renders(): void
    {
        $this->product('Soap')->forceFill(['description' => str_repeat('<p>Long marketing copy.</p>', 200)])->save();

        $this->assertSame(
            ['id', 'name', 'cover', 'stock', 'flash_sale', 'is_offer', 'currency_price', 'discounted_price', 'rating_star', 'rating_star_count'],
            array_keys($this->grid()[0])
        );
    }

    private function cashier(bool $canUsePos): User
    {
        $user = User::create([
            'name'     => 'Cashier',
            'username' => 'cashier' . ++$this->sequence,
            'email'    => 'cashier' . $this->sequence . '@example.test',
            'password' => bcrypt('secret'),
            'status'   => Status::ACTIVE,
        ]);

        if ($canUsePos) {
            Permission::findOrCreate('pos', 'sanctum');
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $user->givePermissionTo('pos');
        }

        return $user;
    }

    public function test_the_till_gets_its_grid_from_the_pos_endpoint(): void
    {
        $main = $this->outlet('Main');
        $this->stock($this->product('Soap'), $main, 7);

        Sanctum::actingAs($this->cashier(true));

        $this->getJson('/api/admin/product/pos-products?paginate=0&outlet_id=' . $main->id)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Soap')
            ->assertJsonPath('data.0.stock', 7)
            ->assertJsonMissingPath('data.0.description');
    }

    public function test_an_account_without_pos_access_is_refused(): void
    {
        $this->product('Soap');

        Sanctum::actingAs($this->cashier(false));

        $this->getJson('/api/admin/product/pos-products')->assertForbidden();
    }
}
