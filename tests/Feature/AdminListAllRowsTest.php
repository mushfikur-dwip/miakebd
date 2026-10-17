<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Role as EnumRole;
use App\Enums\Source;
use App\Enums\Status;
use App\Http\Requests\PaginateRequest;
use App\Models\Order;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * An admin list can show every row at once ("All" in the rows-per-page
 * choice), however many products the shop has. The public storefront keeps
 * its cap of 1000, so nobody outside can ask the server for everything.
 */
class AdminListAllRowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Customer', 'Manager', 'POS Operator', 'Stuff'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'sanctum']);
        }
        Permission::findOrCreate('products', 'sanctum');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $owner = User::create([
            'name' => 'Owner', 'username' => 'owner', 'email' => 'owner@example.test',
            'password' => bcrypt('secret'), 'status' => Status::ACTIVE,
        ]);
        $owner->assignRole(EnumRole::MANAGER);
        $owner->givePermissionTo('products');
        Sanctum::actingAs($owner);

        foreach (['Soap', 'Cream', 'Oil'] as $n => $name) {
            Product::create([
                'name' => $name, 'slug' => strtolower($name), 'sku' => 'SKU' . $n, 'status' => Status::ACTIVE,
                'can_purchasable' => Ask::YES, 'buying_price' => 100, 'selling_price' => 150, 'variation_price' => 150,
            ]);
        }
    }

    public function test_an_admin_list_can_show_every_row(): void
    {
        $response = $this->getJson('/api/admin/product?paginate=1&page=1&per_page=' . PaginateRequest::ADMIN_ALL)->assertOk();

        $this->assertCount(3, $response->json('data'));
        $this->assertEquals(PaginateRequest::ADMIN_ALL, $response->json('meta.per_page'));
        $this->assertEquals(1, $response->json('meta.last_page'), 'everything on one page');
    }

    // "All" is only usable if a longer list costs no more queries: before,
    // every product loaded every line it had ever sold, one query each.
    public function test_every_row_comes_without_a_query_per_product(): void
    {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/admin/product?paginate=1&page=1&per_page=' . PaginateRequest::ADMIN_ALL)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->sell(Product::where('name', 'Soap')->first(), 2);
        $queries(); // the first request also warms the permission and settings caches
        $three = $queries();

        foreach (['Serum', 'Toner', 'Mask'] as $n => $name) {
            $this->sell(Product::create([
                'name' => $name, 'slug' => strtolower($name), 'sku' => 'NEW' . $n, 'status' => Status::ACTIVE,
                'can_purchasable' => Ask::YES, 'buying_price' => 100, 'selling_price' => 150, 'variation_price' => 150,
            ]), 1);
        }

        $this->assertSame($three, $queries(), 'six products cost what three did');

        $rows = collect($this->getJson('/api/admin/product?paginate=1&page=1&per_page=' . PaginateRequest::ADMIN_ALL)->json('data'))
            ->pluck('order', 'name');
        $this->assertEquals(2, $rows['Soap'], 'units sold still shown');
        $this->assertEquals(0, $rows['Cream']);
    }

    private function sell(Product $product, int $units): void
    {
        $order = Order::forceCreate([
            'user_id' => User::first()->id, 'subtotal' => 150 * $units, 'total' => 150 * $units,
            'status' => OrderStatus::CONFIRMED, 'payment_status' => PaymentStatus::PAID,
            'order_type' => OrderType::POS, 'source' => Source::POS, 'order_datetime' => now(),
        ]);
        Stock::forceCreate([
            'product_id' => $product->id, 'model_type' => Order::class, 'model_id' => $order->id,
            'item_type' => Product::class, 'item_id' => $product->id, 'quantity' => -$units, 'status' => Status::ACTIVE,
        ]);
    }

    public function test_the_storefront_still_caps_a_page_at_1000(): void
    {
        $this->getJson('/api/frontend/product?paginate=1&per_page=1001')->assertStatus(422);
    }
}
