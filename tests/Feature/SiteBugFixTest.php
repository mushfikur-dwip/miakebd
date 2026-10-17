<?php

namespace Tests\Feature;

use App\Enums\Activity;
use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Events\SendOrderGotMail;
use App\Events\SendOrderGotPush;
use App\Events\SendOrderGotSms;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Events\SendOrderSms;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\PaymentGateway;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Faults found while sweeping the live site, each pinned here so it cannot
 * come back:
 *
 * - A branch could not be saved again without renaming it ("the name has
 *   already been taken" against itself).
 * - /payment/successful/{id} treated any order id as a placed order: it sent
 *   the shop a billable "new order" SMS for checkouts the customer had
 *   abandoned, and took the once-per-order token from orders still at the
 *   gateway, so their real confirmation later sent nothing at all.
 */
class SiteBugFixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([SendOrderMail::class, SendOrderSms::class, SendOrderPush::class,
            SendOrderGotMail::class, SendOrderGotSms::class, SendOrderGotPush::class]);

        PaymentGateway::create(['name' => 'Cash On Delivery', 'slug' => 'cashondelivery', 'status' => Activity::ENABLE]);
    }

    private function staff(): User
    {
        Permission::findOrCreate('settings', 'sanctum');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::create([
            'name' => 'Owner', 'username' => 'owner', 'email' => 'owner@example.test',
            'password' => bcrypt('x'), 'status' => Status::ACTIVE,
        ]);
        $user->givePermissionTo('settings');

        return $user;
    }

    private function branch(string $name): Outlet
    {
        return Outlet::create([
            'name' => $name, 'email' => strtolower($name) . '@example.test', 'phone' => '01700000000',
            'city' => 'Dhaka', 'state' => 'Dhaka', 'zip_code' => '1212',
            'address' => $name . ', Dhaka', 'status' => Status::ACTIVE,
        ]);
    }

    private function payload(Outlet $branch, array $overrides = []): array
    {
        return $overrides + [
            'name' => $branch->name, 'email' => $branch->email, 'phone' => $branch->phone,
            'city' => $branch->city, 'state' => $branch->state, 'zip_code' => $branch->zip_code,
            'address' => $branch->address, 'status' => Status::ACTIVE,
        ];
    }

    // ------------------------------------------------------------ branches

    public function test_a_branch_can_be_saved_again_without_renaming_it(): void
    {
        Sanctum::actingAs($this->staff());
        $branch = $this->branch('Gulshan');

        // What the edit form submits when only the address was corrected: the
        // name is sent back unchanged, and must not read as a duplicate of
        // the very row being saved.
        $this->putJson('/api/admin/setting/outlet/' . $branch->id, $this->payload($branch, ['address' => 'Road 11, Gulshan']))
            ->assertOk();

        $this->assertSame('Road 11, Gulshan', $branch->fresh()->address);
    }

    public function test_two_branches_still_cannot_share_a_name(): void
    {
        Sanctum::actingAs($this->staff());
        $this->branch('Gulshan');
        $banani = $this->branch('Banani');

        $this->putJson('/api/admin/setting/outlet/' . $banani->id, $this->payload($banani, ['name' => 'Gulshan']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame('Banani', $banani->fresh()->name);
    }

    public function test_a_new_branch_is_added(): void
    {
        Sanctum::actingAs($this->staff());
        $this->branch('Gulshan');

        $this->postJson('/api/admin/setting/outlet', [
            'name' => 'Dhanmondi', 'city' => 'Dhaka', 'state' => 'Dhaka', 'zip_code' => '1209',
            'address' => 'Road 27, Dhanmondi', 'status' => Status::ACTIVE,
        ])->assertCreated();

        $this->assertSame(2, Outlet::count());
    }

    // ------------------------------------------------- order confirmation

    private function order(array $attributes = []): Order
    {
        $customer = User::create([
            'name' => 'Rima', 'username' => 'rima' . uniqid(), 'phone' => '1711111111', 'country_code' => '+880',
            'is_guest' => Ask::YES, 'status' => Status::ACTIVE, 'password' => bcrypt('x'),
        ]);

        return Order::create($attributes + [
            'user_id' => $customer->id, 'order_type' => OrderType::DELIVERY, 'subtotal' => 1000, 'total' => 1120,
            'discount' => 0, 'tax' => 0, 'shipping_charge' => 120, 'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID, 'source' => 5, 'payment_method' => 1,
            'active' => Ask::YES, 'order_datetime' => now(),
        ]);
    }

    public function test_a_placed_order_is_still_confirmed_and_announced(): void
    {
        $order = $this->order();

        $this->get('/payment/successful/' . $order->id)->assertRedirect();

        Event::assertDispatched(SendOrderSms::class);
        Event::assertDispatched(SendOrderGotSms::class);
    }

    /**
     * Someone walking order ids, or a customer who closed the gateway tab:
     * the order was never committed, so nobody is told it was placed.
     */
    public function test_an_abandoned_checkout_tells_nobody(): void
    {
        $order = $this->order(['active' => Ask::NO]);

        $this->get('/payment/successful/' . $order->id)->assertRedirect();

        Event::assertNotDispatched(SendOrderSms::class);
        Event::assertNotDispatched(SendOrderGotSms::class);
    }

    /**
     * The once-per-order token must survive that visit: the order may still be
     * paid, and its real confirmation has to be able to claim it.
     */
    public function test_a_visit_to_an_uncommitted_order_does_not_take_its_token(): void
    {
        $order = $this->order(['active' => Ask::NO]);

        $this->get('/payment/successful/' . $order->id);
        $this->assertNull(Cache::get('order-confirmed-' . $order->id), 'the token was kept');

        // The gateway settles it, and the customer lands here again.
        $order->update(['active' => Ask::YES, 'payment_status' => PaymentStatus::PAID]);
        $this->get('/payment/successful/' . $order->id)->assertRedirect();

        Event::assertDispatched(SendOrderSms::class);
    }

    public function test_a_placed_order_is_announced_only_once(): void
    {
        $order = $this->order();

        $this->get('/payment/successful/' . $order->id);
        $this->get('/payment/successful/' . $order->id);

        Event::assertDispatchedTimes(SendOrderSms::class, 1);
    }
}
