<?php

namespace Tests\Feature;

use App\Enums\Activity;
use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Role as EnumRole;
use App\Enums\ShippingMethod;
use App\Enums\Status;
use App\Events\SendOrderGotMail;
use App\Events\SendOrderGotPush;
use App\Events\SendOrderGotSms;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Events\SendOrderSms;
use App\Http\Requests\AddressRequest;
use App\Models\Address;
use App\Models\Order;
use App\Models\OrderArea;
use App\Models\Product;
use App\Models\User;
use Dipokhalder\Settings\Facades\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The guest checkout as the storefront now drives it: one form whose button
 * starts the guest session, saves the address and places the order.
 *
 * The fixture mirrors the live shop: area-wise shipping, Dhaka 95, default 120.
 */
class GuestCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.api_key' => 'test-key']);

        Event::fake([SendOrderMail::class, SendOrderSms::class, SendOrderPush::class,
            SendOrderGotMail::class, SendOrderGotSms::class, SendOrderGotPush::class]);

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

        $this->product = Product::create([
            'name' => 'Serum', 'slug' => 'serum', 'sku' => 'SERUM1', 'status' => Status::ACTIVE, 'can_purchasable' => Ask::YES,
            'buying_price' => 100, 'selling_price' => 500, 'variation_price' => 500,
        ]);
    }

    private function startGuest(string $phone, string $name = 'Nusrat'): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['x-api-key' => 'test-key'])->postJson('/api/auth/guest/start', [
            'name' => $name, 'phone' => $phone, 'country_code' => '+880',
        ]);
    }

    private function address(array $overrides = []): array
    {
        return $overrides + [
            'full_name' => 'Nusrat Jahan', 'email' => '', 'country_code' => '+880', 'phone' => '01712345678',
            'country' => 'Bangladesh', 'state' => 'Rangpur', 'address' => 'Prime medical college gate, Pirjabad road',
        ];
    }

    private function orderPayload(int $addressId, float $shipping): array
    {
        $line = [
            'product_id' => $this->product->id, 'variation_id' => 0, 'variation_names' => '', 'sku' => 'SERUM1',
            'price' => 500, 'quantity' => 2, 'discount' => 0, 'total_tax' => 0, 'subtotal' => 1000, 'total' => 1000,
            'taxes' => [], 'price_source' => 'catalogue',
        ];

        return [
            'subtotal' => 1000, 'discount' => 0, 'tax' => 0, 'shipping_charge' => $shipping, 'total' => 1000 + $shipping,
            'order_type' => OrderType::DELIVERY, 'shipping_id' => $addressId, 'billing_id' => $addressId,
            'outlet_id' => null, 'coupon_id' => 0, 'source' => 5, 'payment_method' => 1, 'wallet_discount' => 0,
            'products' => json_encode([$line]),
        ];
    }

    public function test_a_guest_goes_from_the_form_to_a_placed_order(): void
    {
        $token = $this->startGuest('01712345678')->assertCreated()->json('token');
        $auth  = ['Authorization' => 'Bearer ' . $token, 'x-api-key' => 'test-key'];

        // Typed on a Bangla keyboard, as many customers do.
        $address = $this->withHeaders($auth)
            ->postJson('/api/frontend/address', $this->address(['phone' => '০১৭১২৩৪৫৬৭৮']))
            ->assertCreated()
            ->json('data');

        $this->assertSame('1712345678', Address::findOrFail($address['id'])->phone);
        $this->assertSame('Rangpur', $address['state']);

        // Rangpur has no order area of its own, so the default 120 applies -
        // the figure the storefront shows once the area list has loaded.
        $this->withHeaders($auth)
            ->postJson('/api/frontend/order', $this->orderPayload($address['id'], 120))
            ->assertCreated();

        $order = Order::sole();
        $this->assertEquals(1120, (float) $order->total);
        $this->assertSame((int) User::where('is_guest', Ask::YES)->sole()->id, (int) $order->user_id);
    }

    public function test_an_order_priced_before_the_area_list_loaded_is_refused(): void
    {
        $guest = User::create([
            'name' => 'Nusrat', 'username' => 'nusrat', 'phone' => '1712345678', 'country_code' => '+880',
            'is_guest' => Ask::YES, 'status' => Status::ACTIVE, 'password' => bcrypt('x'),
        ]);
        $guest->assignRole(EnumRole::CUSTOMER);
        Sanctum::actingAs($guest);

        $dhaka = Address::create(['user_id' => $guest->id] + $this->address(['state' => 'Dhaka', 'phone' => '1712345678']));

        // Why the checkout now re-prices once the order areas arrive: Dhaka at
        // the default 120 instead of its own 95 is a total the server refuses.
        $this->postJson('/api/frontend/order', $this->orderPayload($dhaka->id, 120))->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_address_errors_name_the_district_and_the_mobile_number(): void
    {
        Sanctum::actingAs($this->makeGuest());

        $errors = $this->postJson('/api/frontend/address', $this->address(['state' => '', 'phone' => '0171234']))
            ->assertStatus(422)
            ->json('errors');

        $this->assertSame('Please select your district.', $errors['state'][0]);
        $this->assertSame('Please enter a valid mobile number.', $errors['phone'][0]);
    }

    public function test_every_district_in_the_picker_is_accepted(): void
    {
        $districts = collect(json_decode(file_get_contents(public_path('data/bd-districts.json')), true)['districts'])
            ->pluck('name');

        $this->assertCount(64, $districts);
        $this->assertCount(64, $districts->unique());

        foreach ($districts as $district) {
            $validator = Validator::make($this->address(['state' => $district, 'phone' => '1712345678']), (new AddressRequest())->rules());
            $this->assertFalse($validator->fails(), $district . ': ' . $validator->errors()->first());
        }
    }

    public function test_many_guests_behind_one_ip_can_all_check_out(): void
    {
        // One carrier NAT address, or the CDN edge, in front of 25 shoppers
        // within a minute. The old flat 10-a-minute per-IP limit turned the
        // 11th away.
        for ($i = 0; $i < 25; $i++) {
            $this->startGuest('0171' . str_pad((string) $i, 7, '0', STR_PAD_LEFT))->assertCreated();
        }
    }

    public function test_one_number_cannot_mint_guest_sessions_without_limit(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->startGuest('01712345678')->assertCreated();
        }

        $this->startGuest('01712345678')->assertStatus(429);
    }

    public function test_payment_pages_are_limited_per_order_not_for_the_whole_shop(): void
    {
        $guest = $this->makeGuest();

        // 40 different customers' orders confirmed within a minute through the
        // same IP. The old flat 30-a-minute limit refused the 31st.
        for ($i = 0; $i < 40; $i++) {
            $order = $this->makeOrder($guest);
            $this->get('/payment/successful/' . $order->id)
                ->assertRedirect('/account/order-details/' . $order->id . '?status=success');
        }
    }

    public function test_the_confirmation_notifications_still_go_out_exactly_once(): void
    {
        $order = $this->makeOrder($this->makeGuest());

        // Sent after the redirect now (deferred), and still only on the first
        // visit - a refresh must not send a second SMS.
        $this->get('/payment/successful/' . $order->id)->assertRedirect();
        $this->get('/payment/successful/' . $order->id)->assertRedirect();

        foreach ([SendOrderMail::class, SendOrderSms::class, SendOrderPush::class,
                     SendOrderGotMail::class, SendOrderGotSms::class, SendOrderGotPush::class] as $event) {
            Event::assertDispatchedTimes($event, 1);
        }
    }

    private function makeGuest(): User
    {
        $guest = User::create([
            'name' => 'Nusrat', 'username' => 'nusrat' . uniqid(), 'phone' => '1712345678', 'country_code' => '+880',
            'is_guest' => Ask::YES, 'status' => Status::ACTIVE, 'password' => bcrypt('x'),
        ]);
        $guest->assignRole(EnumRole::CUSTOMER);

        return $guest;
    }

    private function makeOrder(User $user): Order
    {
        return Order::create([
            'user_id' => $user->id, 'order_type' => OrderType::DELIVERY, 'subtotal' => 1000, 'total' => 1120,
            'discount' => 0, 'tax' => 0, 'shipping_charge' => 120, 'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID, 'source' => 5, 'payment_method' => 1, 'active' => Ask::YES,
            'order_datetime' => now(),
        ]);
    }
}
