<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Role as EnumRole;
use App\Enums\Source;
use App\Enums\Status;
use App\Jobs\SendMetaConversionEvent;
use App\Models\Order;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The Conversions API: the same events as the browser pixel, sent from the
 * server so ad blockers and iOS cannot hide them.
 *
 * Two rules are load-bearing. Money is always priced from the database, never
 * from the request - the value is what ad bidding optimises on, so a forgeable
 * one would poison it. And Purchase is server-only, so a browser can never
 * declare a sale that did not happen.
 */
class MetaConversionsTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta_pixel.id'    => '1234567890123456',
            'services.meta_pixel.token' => 'test-token',
        ]);

        foreach (['Admin', 'Customer', 'Manager', 'POS Operator', 'Stuff'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'sanctum']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->product = Product::create([
            'name' => 'Serum', 'slug' => 'serum', 'sku' => 'SERUM1', 'status' => Status::ACTIVE,
            'can_purchasable' => Ask::YES, 'buying_price' => 100, 'selling_price' => 500, 'variation_price' => 500,
        ]);
    }

    private function customer(): User
    {
        $user = User::create([
            'name' => 'Rima Akter', 'username' => 'rima', 'email' => 'rima@example.com', 'password' => bcrypt('secret123'),
            'phone' => '1711111111', 'country_code' => '+880', 'is_guest' => Ask::NO, 'status' => Status::ACTIVE,
        ]);
        $user->assignRole(EnumRole::CUSTOMER);

        return $user;
    }

    private function order(int $source = Source::WEB, int $orderType = OrderType::DELIVERY): Order
    {
        $order = Order::create([
            'user_id' => $this->customer()->id, 'order_type' => $orderType, 'subtotal' => 1000, 'total' => 1095,
            'discount' => 0, 'tax' => 0, 'shipping_charge' => 95, 'status' => 1, 'payment_status' => PaymentStatus::UNPAID,
            'source' => $source, 'payment_method' => 1, 'active' => Ask::YES, 'order_datetime' => now(),
        ]);

        Stock::create([
            'product_id' => $this->product->id, 'model_type' => Order::class, 'model_id' => $order->id,
            'item_type' => Product::class, 'item_id' => $this->product->id, 'variation_names' => '', 'sku' => 'SERUM1',
            'price' => 500, 'quantity' => -2, 'discount' => 0, 'tax' => 0, 'subtotal' => 1000, 'total' => 1000,
            'status' => Status::ACTIVE,
        ]);

        return $order;
    }

    private function dispatched(): array
    {
        $events = [];
        Bus::assertDispatchedAfterResponse(SendMetaConversionEvent::class, function ($job) use (&$events) {
            $events[] = $job->event;
            return true;
        });

        return $events;
    }

    // --- the browser mirror ------------------------------------------------

    public function test_a_product_view_is_reported_with_the_price_from_the_database(): void
    {
        Bus::fake();

        $this->postJson('/api/frontend/track', [
            'event'      => 'ViewContent',
            'event_id'   => 'abc-123',
            'product_id' => $this->product->id,
        ])->assertNoContent();

        $event = $this->dispatched()[0];
        $this->assertSame('ViewContent', $event['event_name']);
        $this->assertSame('abc-123', $event['event_id'], 'the id the browser used must be kept, or Meta counts the event twice');
        $this->assertEquals(500, $event['custom_data']['value']);
    }

    public function test_the_value_comes_from_the_database_not_the_request(): void
    {
        Bus::fake();

        $this->postJson('/api/frontend/track', [
            'event'      => 'AddToCart',
            'event_id'   => 'abc-124',
            'product_id' => $this->product->id,
            'quantity'   => 2,
            // A crafted request trying to report a huge conversion.
            'value'      => 999999,
            'currency'   => 'USD',
        ])->assertNoContent();

        $event = $this->dispatched()[0];
        $this->assertEquals(1000, $event['custom_data']['value']);
        $this->assertSame('BDT', $event['custom_data']['currency']);
    }

    public function test_an_offer_price_is_the_one_reported(): void
    {
        Bus::fake();
        $this->product->update([
            'discount' => 10, 'offer_start_date' => now()->subDay(), 'offer_end_date' => now()->addDay(),
        ]);

        $this->postJson('/api/frontend/track', [
            'event' => 'ViewContent', 'event_id' => 'abc-125', 'product_id' => $this->product->id,
        ])->assertNoContent();

        $this->assertEquals(450, $this->dispatched()[0]['custom_data']['value']);
    }

    public function test_a_browser_cannot_report_a_purchase(): void
    {
        Bus::fake();

        $this->postJson('/api/frontend/track', [
            'event' => 'Purchase', 'event_id' => 'abc-126', 'product_id' => $this->product->id,
        ])->assertStatus(422);

        Bus::assertNotDispatchedAfterResponse(SendMetaConversionEvent::class);
    }

    public function test_an_unknown_product_reports_nothing(): void
    {
        Bus::fake();

        $this->postJson('/api/frontend/track', [
            'event' => 'ViewContent', 'event_id' => 'abc-127', 'product_id' => 999999,
        ])->assertStatus(422);

        Bus::assertNotDispatchedAfterResponse(SendMetaConversionEvent::class);
    }

    public function test_nothing_is_sent_without_an_access_token(): void
    {
        Bus::fake();
        config(['services.meta_pixel.token' => null]);

        $this->postJson('/api/frontend/track', [
            'event' => 'ViewContent', 'event_id' => 'abc-128', 'product_id' => $this->product->id,
        ])->assertNoContent();

        Bus::assertNotDispatchedAfterResponse(SendMetaConversionEvent::class);
    }

    /** Personal details may only ever leave here hashed. */
    public function test_customer_details_are_hashed(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->customer());

        $this->postJson('/api/frontend/track', [
            'event' => 'ViewContent', 'event_id' => 'abc-129', 'product_id' => $this->product->id,
        ])->assertNoContent();

        $userData = $this->dispatched()[0]['user_data'];
        $serialised = json_encode($userData);

        $this->assertStringNotContainsString('rima@example.com', $serialised);
        $this->assertStringNotContainsString('1711111111', $serialised);
        $this->assertStringNotContainsString('Rima', $serialised);
        $this->assertSame(hash('sha256', 'rima@example.com'), $userData['em']);
        $this->assertSame(hash('sha256', '8801711111111'), $userData['ph']);
    }

    // --- the purchase, from the order --------------------------------------

    public function test_an_order_reports_a_purchase_from_the_server(): void
    {
        Bus::fake();

        $order = $this->order();

        $event = $this->dispatched()[0];
        $this->assertSame('Purchase', $event['event_name']);
        // The browser sends the same id for this order, so Meta keeps one.
        $this->assertSame('order-' . $order->id, $event['event_id']);
        $this->assertEquals(1095, $event['custom_data']['value']);
        $this->assertSame('BDT', $event['custom_data']['currency']);
    }

    public function test_a_till_order_is_not_reported_as_an_ad_conversion(): void
    {
        Bus::fake();

        $this->order(Source::POS, OrderType::POS);

        Bus::assertNotDispatchedAfterResponse(SendMetaConversionEvent::class);
    }
}
