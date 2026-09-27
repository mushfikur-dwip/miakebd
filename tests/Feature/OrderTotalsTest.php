<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\DiscountType;
use App\Enums\OrderType;
use App\Enums\Role as EnumRole;
use App\Enums\ShippingMethod;
use App\Enums\Status;
use App\Models\Address;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderArea;
use App\Models\OrderCoupon;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use Dipokhalder\Settings\Facades\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Discount, tax, shipping and total used to be written exactly as the browser
 * sent them - "total": 1 was a one-taka order that bKash charged and the COD
 * rider collected. OrderTotals now works them out and refuses any other figure.
 *
 * The fixture mirrors the live shop: area-wise shipping, Dhaka 95, default 120.
 */
class OrderTotalsTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;
    private Product $product;
    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Customer', 'Manager', 'POS Operator', 'Stuff'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'sanctum']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Settings::group('shipping_setup')->set([
            'shipping_setup_method'                 => ShippingMethod::AREA_WISE,
            'shipping_setup_flat_rate_wise_cost'    => 130,
            'shipping_setup_area_wise_default_cost' => 120,
        ]);
        OrderArea::create(['country' => 'Bangladesh', 'state' => 'Dhaka', 'shipping_cost' => 95, 'status' => Status::ACTIVE]);

        $this->customer = User::create([
            'name' => 'Rima', 'username' => 'rima', 'email' => 'rima@example.com', 'password' => bcrypt('secret123'),
            'phone' => '01711111111', 'country_code' => '+880', 'is_guest' => Ask::NO, 'status' => Status::ACTIVE,
        ]);
        $this->customer->assignRole(EnumRole::CUSTOMER);
        Sanctum::actingAs($this->customer);

        $this->address = Address::create([
            'user_id' => $this->customer->id, 'full_name' => 'Rima', 'phone' => '01711111111', 'country_code' => '+880',
            'country' => 'Bangladesh', 'state' => 'Dhaka', 'address' => 'House 1',
        ]);

        $this->product = Product::create([
            'name' => 'Serum', 'slug' => 'serum', 'sku' => 'SERUM1', 'status' => Status::ACTIVE, 'can_purchasable' => Ask::YES,
            'buying_price' => 100, 'selling_price' => 500, 'variation_price' => 500,
            // The live catalogue's usual limit; the column's own default is 1.
            'maximum_purchase_quantity' => 100,
        ]);
    }

    private function line(array $overrides = []): array
    {
        return $overrides + [
            'product_id'      => $this->product->id,
            'variation_id'    => 0,
            'variation_names' => '',
            'sku'             => 'SERUM1',
            'price'           => 500,
            'quantity'        => 2,
            'discount'        => 0,
            'total_tax'       => 0,
            'subtotal'        => 1000,
            'total'           => 1000,
            'taxes'           => [],
            'price_source'    => 'catalogue',
        ];
    }

    private function order(array $overrides = [], ?array $lines = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/frontend/order', $overrides + [
            'subtotal'        => 1000,
            'discount'        => 0,
            'tax'             => 0,
            'shipping_charge' => 95,
            'total'           => 1095,
            'order_type'      => OrderType::DELIVERY,
            'shipping_id'     => $this->address->id,
            'billing_id'      => $this->address->id,
            'outlet_id'       => null,
            'coupon_id'       => 0,
            'source'          => 5,
            'payment_method'  => 1,
            'wallet_discount' => 0,
            'products'        => json_encode($lines ?? [$this->line()]),
        ]);
    }

    private function coupon(array $attributes = []): Coupon
    {
        return Coupon::create($attributes + [
            'name' => 'Ten off', 'code' => 'TEN', 'discount' => 10, 'discount_type' => DiscountType::PERCENTAGE,
            'minimum_order' => 0, 'maximum_discount' => 1000, 'limit_per_user' => 5,
            'start_date' => now()->subDay(), 'end_date' => now()->addDay(),
        ]);
    }

    public function test_an_honest_order_is_stored_with_the_server_figures(): void
    {
        $this->order()->assertCreated();

        $order = Order::sole();
        $this->assertEquals(1095, (float) $order->total);
        $this->assertEquals(95, (float) $order->shipping_charge);
    }

    public function test_a_lowered_total_is_refused(): void
    {
        $this->order(['total' => 1])->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    public function test_a_negative_quantity_line_is_refused(): void
    {
        $lines = [
            $this->line(),
            $this->line(['quantity' => -1, 'subtotal' => -500, 'total' => -500]),
        ];

        $this->order(['subtotal' => 500, 'total' => 595], $lines)->assertStatus(422);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Stock::count());
    }

    public function test_a_discount_with_no_coupon_behind_it_is_refused(): void
    {
        $this->order(['discount' => 500, 'total' => 595])->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    public function test_skipping_the_shipping_charge_is_refused(): void
    {
        $this->order(['shipping_charge' => 0, 'total' => 1000])->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    public function test_a_coupon_discount_is_accepted_up_to_what_the_coupon_is_worth(): void
    {
        $coupon = $this->coupon();

        $this->order(['coupon_id' => $coupon->id, 'discount' => 100, 'total' => 995])->assertCreated();

        $this->assertEquals(995, (float) Order::sole()->total);
        $this->assertEquals(100, (float) OrderCoupon::sole()->discount);
    }

    public function test_a_coupon_discount_above_its_value_is_refused(): void
    {
        $coupon = $this->coupon();

        $this->order(['coupon_id' => $coupon->id, 'discount' => 300, 'total' => 795])->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    public function test_a_used_up_coupon_is_refused_even_when_sent_directly(): void
    {
        $coupon = $this->coupon(['limit_per_user' => 1]);
        // A completed earlier order (active), so the checkout's clean-up of
        // abandoned orders leaves its coupon row alone.
        $earlier = Order::create([
            'user_id' => $this->customer->id, 'order_type' => OrderType::DELIVERY, 'subtotal' => 1000, 'total' => 995,
            'discount' => 100, 'tax' => 0, 'shipping_charge' => 95, 'status' => 1, 'payment_status' => 10,
            'source' => 5, 'payment_method' => 1, 'active' => Ask::YES, 'order_datetime' => now(),
        ]);
        OrderCoupon::create(['order_id' => $earlier->id, 'coupon_id' => $coupon->id, 'user_id' => $this->customer->id, 'discount' => 100]);

        $this->order(['coupon_id' => $coupon->id, 'discount' => 100, 'total' => 995])->assertStatus(422);
    }

    public function test_an_expired_coupon_is_refused_even_when_sent_directly(): void
    {
        $coupon = $this->coupon(['start_date' => now()->subWeek(), 'end_date' => now()->subDay()]);

        $this->order(['coupon_id' => $coupon->id, 'discount' => 100, 'total' => 995])->assertStatus(422);
    }

    // One request per test from here: a refused order rolls back, and under
    // RefreshDatabase that rollback also takes the test's own fixtures with it.

    private function putOnOffer(): array
    {
        $this->product->update([
            'discount' => 10, 'offer_start_date' => now()->subDay(), 'offer_end_date' => now()->addDay(),
        ]);

        return $this->line(['price' => 450, 'discount' => 50, 'subtotal' => 900, 'total' => 900]);
    }

    /**
     * An offer price is already discounted. The cart used to take the line's
     * `discount` off again (900 - 50 + 95); that figure is now refused.
     */
    public function test_an_offer_item_is_not_discounted_twice(): void
    {
        $this->order(['subtotal' => 900, 'total' => 945], [$this->putOnOffer()])->assertStatus(422);
    }

    public function test_an_offer_item_is_charged_the_offer_price_once(): void
    {
        $this->order(['subtotal' => 900, 'total' => 995], [$this->putOnOffer()])->assertCreated();

        $this->assertEquals(995, (float) Order::sole()->total);
    }

    public function test_an_address_outside_the_listed_areas_cannot_take_a_listed_rate(): void
    {
        $this->address->update(['state' => 'Sylhet']);

        $this->order()->assertStatus(422);
    }

    public function test_an_address_outside_the_listed_areas_pays_the_default_rate(): void
    {
        $this->address->update(['state' => 'Sylhet']);

        $this->order(['shipping_charge' => 120, 'total' => 1120])->assertCreated();
    }

    // The product page and side cart stop at a product's purchase limit, but
    // the cart page did not, and the server never checked it at all.
    public function test_a_line_above_the_purchase_limit_is_refused(): void
    {
        $this->product->update(['maximum_purchase_quantity' => 1]);

        $this->order()->assertStatus(422)->assertJsonPath('message', 'You can buy at most 1 of Serum in one order.');

        $this->assertSame(0, Order::count());
    }

    public function test_a_line_at_the_purchase_limit_goes_through(): void
    {
        $this->product->update(['maximum_purchase_quantity' => 2]);

        $this->order()->assertCreated();
    }
}
