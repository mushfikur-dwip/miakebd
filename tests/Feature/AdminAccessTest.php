<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Role as EnumRole;
use App\Enums\Status;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * /api/admin used to ask only for a valid token. Every customer has one, and
 * guest checkout hands one to anyone with a phone number - so any admin
 * method missing from its controller's permission list was public. These pin
 * the two layers that close that: the staff gate on the whole group, and the
 * per-method permissions on the endpoints that move money.
 */
class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // assignRole() resolves roles by id, and App\Enums\Role fixes the ids.
        foreach (['Admin', 'Customer', 'Manager', 'POS Operator', 'Stuff'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'sanctum']);
        }
        foreach (['customers_edit', 'customers_show', 'products_edit', 'subscribers', 'pos'] as $permission) {
            Permission::findOrCreate($permission, 'sanctum');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function user(int $role, int $guest = Ask::NO): User
    {
        $slug = 'user' . random_int(1, 999999);

        $user = User::create([
            'name'         => $slug,
            'username'     => $slug,
            'email'        => $slug . '@example.com',
            'password'     => bcrypt('secret123'),
            'phone'        => '017' . random_int(10000000, 99999999),
            'country_code' => '+880',
            'is_guest'     => $guest,
            'status'       => Status::ACTIVE,
        ]);
        $user->assignRole($role);

        return $user;
    }

    public function test_a_customer_cannot_credit_their_own_wallet(): void
    {
        $customer = $this->user(EnumRole::CUSTOMER);
        Sanctum::actingAs($customer);

        $this->postJson("/api/admin/customer/wallet-credit/{$customer->id}", ['amount' => 100000, 'note' => 'free money'])
            ->assertForbidden();

        $this->assertEquals(0, (float) $customer->fresh()->balance);
    }

    public function test_a_guest_checkout_token_cannot_reach_the_admin_api(): void
    {
        $guest = $this->user(EnumRole::CUSTOMER, Ask::YES);
        Sanctum::actingAs($guest);

        $this->getJson('/api/admin/timezone')->assertForbidden();
        $this->postJson("/api/admin/customer/wallet-credit/{$guest->id}", ['amount' => 50, 'note' => 'x'])->assertForbidden();
    }

    public function test_staff_without_the_edit_permission_cannot_credit_a_wallet(): void
    {
        $customer = $this->user(EnumRole::CUSTOMER);
        $cashier  = $this->user(EnumRole::POS_OPERATOR);
        Role::findById(EnumRole::POS_OPERATOR, 'sanctum')->givePermissionTo('pos');
        Sanctum::actingAs($cashier);

        $this->postJson("/api/admin/customer/wallet-credit/{$customer->id}", ['amount' => 500, 'note' => 'x'])
            ->assertForbidden();

        $this->assertEquals(0, (float) $customer->fresh()->balance);
    }

    public function test_staff_with_the_edit_permission_can_credit_a_wallet(): void
    {
        $customer = $this->user(EnumRole::CUSTOMER);
        $manager  = $this->user(EnumRole::MANAGER);
        Role::findById(EnumRole::MANAGER, 'sanctum')->givePermissionTo('customers_edit');
        Sanctum::actingAs($manager);

        $this->postJson("/api/admin/customer/wallet-credit/{$customer->id}", ['amount' => 500, 'note' => 'goodwill'])
            ->assertOk();

        $this->assertEquals(500, (float) $customer->fresh()->balance);
    }

    public function test_a_customer_cannot_put_a_product_on_offer(): void
    {
        $product = Product::create([
            'name' => 'Serum', 'slug' => 'serum', 'sku' => 'SERUM1', 'status' => Status::ACTIVE,
            'can_purchasable' => Ask::YES, 'buying_price' => 100, 'selling_price' => 500, 'variation_price' => 500,
        ]);
        Sanctum::actingAs($this->user(EnumRole::CUSTOMER));

        $this->postJson("/api/admin/product/offer/{$product->id}", [
            'add_to_flash_sale' => 10, 'discount' => 100,
            'offer_start_date' => now()->subDay()->toDateTimeString(), 'offer_end_date' => now()->addYear()->toDateTimeString(),
        ])->assertForbidden();

        $this->assertEquals(0, (float) $product->fresh()->discount);
    }

    public function test_a_customer_cannot_mail_the_subscriber_list(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->user(EnumRole::CUSTOMER));

        $this->postJson('/api/admin/subscriber/send-email', ['subject' => 'Your account', 'message' => 'Click here'])
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    /**
     * Every admin route must name a permission, except a short, reviewed list
     * of read-only lookups that many admin screens share. A new admin method
     * added without a permission fails here instead of shipping open.
     */
    public function test_every_admin_route_declares_a_permission(): void
    {
        $readOnlyLookups = [
            'BarcodeController@index',
            'CityController@show', 'CityController@citiesByState',
            'CountryCodeController@index', 'CountryCodeController@show', 'CountryCodeController@callingCode',
            'CountryController@show',
            'MobileSectionController@index',
            'ProductCategoryController@depthTree', 'ProductCategoryController@ancestorsAndSelf', 'ProductCategoryController@tree',
            'ProductController@posProduct', 'ProductController@purchasableProducts',
            'ProductController@simpleProducts', 'ProductController@barcodeProduct',
            'ProductVariationController@index', 'ProductVariationController@tree', 'ProductVariationController@singleTree',
            'ProductVariationController@treeWithSelected', 'ProductVariationController@ancestorsToString',
            'ProductVariationController@barcodeVariationProduct', 'ProductVariationController@downloadBarcode',
            'ProductVariationController@initialVariation', 'ProductVariationController@childrenVariation',
            'ProductVariationController@ancestorsAndSelfId',
            'StateController@simpleLists', 'StateController@statesByCountry',
            'TimezoneController@index',
        ];

        $open = [];
        foreach (Route::getRoutes() as $route) {
            if (!str_starts_with($route->uri(), 'api/admin/')) {
                continue;
            }

            $this->assertContains('staff', $route->gatherMiddleware(), $route->uri() . ' is outside the staff gate');

            [$class, $method] = array_pad(explode('@', $route->getActionName()), 2, null);
            $covered = $class && $method && is_subclass_of($class, HasMiddleware::class)
                && collect($class::middleware())->contains(fn($m) => is_string($m->middleware)
                    && str_starts_with($m->middleware, 'permission:')
                    && ($m->only === null || in_array($method, $m->only, true))
                    && ($m->except === null || !in_array($method, $m->except, true)));

            if (!$covered) {
                $open[] = class_basename((string) $class) . '@' . $method;
            }
        }

        $this->assertSame([], array_values(array_diff(array_unique($open), $readOnlyLookups)));
    }
}
