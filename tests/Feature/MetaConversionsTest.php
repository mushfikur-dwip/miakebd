<?php

namespace Tests\Feature;

use App\Enums\AddressType;
use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Role as EnumRole;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Services\MetaConversionsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The Conversions API: the same events as the browser pixel, sent from the
 * server so ad blockers and iOS cannot hide them.
 *
 * Rules that are load-bearing:
 *   - money is always priced from the database, never from the request;
 *   - Purchase is server-only, and only for a real sale (cash on delivery, or
 *     an online payment that went through);
 *   - a request only stores the event - nothing talks to Facebook until the
 *     per-minute batch, and a failure there never loses or blocks events.
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
            'name' => 'Salicylic Acid Acne Serum', 'slug' => 'serum', 'sku' => 'SERUM1', 'status' => Status::ACTIVE,
            'can_purchasable' => Ask::YES, 'buying_price' => 100, 'selling_price' => 500, 'variation_price' => 500,
        ]);
    }

    private function customer(): User
    {
        $user = User::create([
            'name' => 'Rima Akter', 'username' => 'rima', 'email' => 'Rima@Example.com', 'password' => bcrypt('secret123'),
            'phone' => '1711111111', 'country_code' => '+880', 'is_guest' => Ask::NO, 'status' => Status::ACTIVE,
        ]);
        $user->assignRole(EnumRole::CUSTOMER);

        return $user;
    }

    /**
     * Placed the way FrontendOrderService places one: the order row first, then
     * its lines and address, all in one transaction.
     */
    private function order(int $source = Source::WEB, int $orderType = OrderType::DELIVERY, int $paymentMethod = PaymentGateway::CASH_ON_DELIVERY): Order
    {
        $user = $this->customer();

        return DB::transaction(function () use ($user, $source, $orderType, $paymentMethod) {
            $order = Order::create([
                'user_id' => $user->id, 'order_type' => $orderType, 'subtotal' => 1000, 'total' => 1095,
                'discount' => 0, 'tax' => 0, 'shipping_charge' => 95, 'status' => 1, 'payment_status' => PaymentStatus::UNPAID,
                'source' => $source, 'payment_method' => $paymentMethod, 'active' => Ask::YES, 'order_datetime' => now(),
            ]);

            Stock::create([
                'product_id' => $this->product->id, 'model_type' => Order::class, 'model_id' => $order->id,
                'item_type' => Product::class, 'item_id' => $this->product->id, 'variation_names' => '', 'sku' => 'SERUM1',
                'price' => 500, 'quantity' => -2, 'discount' => 0, 'tax' => 0, 'subtotal' => 1000, 'total' => 1000,
                'status' => Status::ACTIVE,
            ]);

            OrderAddress::create([
                'order_id' => $order->id, 'user_id' => $user->id, 'address_type' => AddressType::SHIPPING,
                'full_name' => 'Rima Akter', 'phone' => '1711111111', 'country' => 'Bangladesh',
                'address' => 'House 1', 'state' => 'Rangpur', 'city' => 'Rangpur Sadar', 'zip_code' => '5400',
            ]);

            return $order;
        });
    }

    /** @return array<int,array> stored events, oldest first */
    private function stored(): array
    {
        return DB::table('meta_events')->orderBy('id')->get()
            ->map(fn($row) => json_decode($row->payload, true) + ['_ready' => (bool) $row->ready, '_sent' => $row->sent_at !== null])
            ->all();
    }

    // --- the browser mirror ------------------------------------------------

    public function test_a_product_view_is_stored_with_the_price_from_the_database(): void
    {
        Http::fake();

        $this->postJson('/api/frontend/track', [
            'event'      => 'ViewContent',
            'event_id'   => 'abc-123',
            'product_id' => $this->product->id,
            'source_url' => url('/product/serum?fbclid=IwAR1234567890&q=acne'),
        ])->assertNoContent();

        $event = $this->stored()[0];
        $this->assertSame('ViewContent', $event['event_name']);
        $this->assertSame('abc-123', $event['event_id'], 'the id the browser used must be kept, or Meta counts the event twice');
        $this->assertEquals(500, $event['custom_data']['value']);
        // Health-sounding product names never go to Meta; the catalogue has them.
        $this->assertArrayNotHasKey('content_name', $event['custom_data']);
        $this->assertStringNotContainsString('Acne', json_encode($event));
        // No query string: search terms and click ids stay out of Meta's copy.
        $this->assertSame(url('/product/serum'), $event['event_source_url']);

        // Stored, not sent: nothing talks to Facebook during a request.
        Http::assertNothingSent();
    }

    public function test_the_value_comes_from_the_database_not_the_request(): void
    {
        $this->postJson('/api/frontend/track', [
            'event'      => 'AddToCart',
            'event_id'   => 'abc-124',
            'product_id' => $this->product->id,
            'quantity'   => 2,
            // A crafted request trying to report a huge conversion.
            'value'      => 999999,
            'currency'   => 'USD',
        ])->assertNoContent();

        $event = $this->stored()[0];
        $this->assertEquals(1000, $event['custom_data']['value']);
        $this->assertSame('BDT', $event['custom_data']['currency']);
    }

    public function test_an_offer_price_is_the_one_reported(): void
    {
        $this->product->update([
            'discount' => 10, 'offer_start_date' => now()->subDay(), 'offer_end_date' => now()->addDay(),
        ]);

        $this->postJson('/api/frontend/track', [
            'event' => 'ViewContent', 'event_id' => 'abc-125', 'product_id' => $this->product->id,
        ])->assertNoContent();

        $this->assertEquals(450, $this->stored()[0]['custom_data']['value']);
    }

    public function test_the_catalogue_can_be_keyed_on_sku(): void
    {
        config(['services.meta_pixel.content_id' => 'sku']);

        $this->postJson('/api/frontend/track', [
            'event' => 'ViewContent', 'event_id' => 'abc-sku', 'product_id' => $this->product->id,
        ])->assertNoContent();

        $this->assertSame(['SERUM1'], $this->stored()[0]['custom_data']['content_ids']);
    }

    public function test_a_browser_cannot_report_a_purchase(): void
    {
        $this->postJson('/api/frontend/track', [
            'event' => 'Purchase', 'event_id' => 'abc-126', 'product_id' => $this->product->id,
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('meta_events')->count());
    }

    public function test_an_unknown_product_reports_nothing(): void
    {
        $this->postJson('/api/frontend/track', [
            'event' => 'ViewContent', 'event_id' => 'abc-127', 'product_id' => 999999,
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('meta_events')->count());
    }

    public function test_nothing_is_stored_without_an_access_token(): void
    {
        config(['services.meta_pixel.token' => null]);

        $this->postJson('/api/frontend/track', [
            'event' => 'ViewContent', 'event_id' => 'abc-128', 'product_id' => $this->product->id,
        ])->assertNoContent();

        $this->assertSame(0, DB::table('meta_events')->count());
    }

    /** Personal details may only ever leave here hashed - and normalised first, or Meta cannot match them. */
    public function test_customer_details_are_normalised_and_hashed(): void
    {
        Sanctum::actingAs($this->customer());

        $this->withCredentials()->withUnencryptedCookie('_fbc', 'fb.1.1727200000000.IwAR1234567890')
            ->withUnencryptedCookie('_fbp', 'fb.1.1727200000000.1234567890')
            ->postJson('/api/frontend/track', [
                'event' => 'ViewContent', 'event_id' => 'abc-129', 'product_id' => $this->product->id,
            ])->assertNoContent();

        $userData = $this->stored()[0]['user_data'];
        $serialised = json_encode($userData);

        $this->assertStringNotContainsString('rima@example.com', strtolower($serialised));
        $this->assertStringNotContainsString('1711111111', $serialised);
        $this->assertStringNotContainsString('Rima', $serialised);
        $this->assertSame(hash('sha256', 'rima@example.com'), $userData['em'], 'email is lowercased before hashing');
        $this->assertSame(hash('sha256', '8801711111111'), $userData['ph']);
        $this->assertSame(hash('sha256', 'rima'), $userData['fn']);
        // The ad click id is what credits a sale to its ad.
        $this->assertSame('fb.1.1727200000000.IwAR1234567890', $userData['fbc']);
        $this->assertSame('fb.1.1727200000000.1234567890', $userData['fbp']);
    }

    public function test_a_malformed_click_cookie_is_not_forwarded(): void
    {
        $this->withCredentials()->withUnencryptedCookie('_fbc', 'not-a-click-id')
            ->postJson('/api/frontend/track', [
                'event' => 'ViewContent', 'event_id' => 'abc-130', 'product_id' => $this->product->id,
            ])->assertNoContent();

        $this->assertArrayNotHasKey('fbc', $this->stored()[0]['user_data']);
    }

    // --- the purchase, from the order --------------------------------------

    public function test_a_cash_on_delivery_order_is_reported_as_a_purchase(): void
    {
        $order = $this->order();

        $events = $this->stored();
        $this->assertCount(1, $events);
        $event = $events[0];

        $this->assertSame('Purchase', $event['event_name']);
        // The browser sends the same id for this order, so Meta keeps one.
        $this->assertSame('order-' . $order->id, $event['event_id']);
        $this->assertTrue($event['_ready'], 'cash on delivery is a sale the moment it is placed');
        $this->assertEquals(1095, $event['custom_data']['value']);
        $this->assertSame('BDT', $event['custom_data']['currency']);
        // Stored after the transaction, so the lines and address are there.
        $this->assertSame([(string) $this->product->id], $event['custom_data']['content_ids']);
        $this->assertSame(2, $event['custom_data']['num_items']);
        $this->assertSame(hash('sha256', 'rangpursadar'), $event['user_data']['ct']);
        $this->assertSame(hash('sha256', 'bd'), $event['user_data']['country']);
    }

    public function test_an_online_payment_is_held_until_it_is_paid(): void
    {
        $order = $this->order(Source::WEB, OrderType::DELIVERY, 7);

        $this->assertFalse($this->stored()[0]['_ready'], 'an unpaid online order is not a sale');

        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
        app(MetaConversionsService::class)->sendPending();
        Http::assertNothingSent();

        $order->update(['payment_status' => PaymentStatus::PAID]);

        $this->assertTrue($this->stored()[0]['_ready']);
        $this->assertSame(1, app(MetaConversionsService::class)->sendPending());
    }

    public function test_a_purchase_is_stored_once_per_order(): void
    {
        $order = $this->order();

        $order->update(['payment_status' => PaymentStatus::PAID]);
        $order->update(['payment_status' => PaymentStatus::UNPAID]);
        $order->update(['payment_status' => PaymentStatus::PAID]);

        $this->assertSame(1, DB::table('meta_events')->count());
    }

    public function test_a_till_order_is_not_reported_as_an_ad_conversion(): void
    {
        $this->order(Source::POS, OrderType::POS);

        $this->assertSame(0, DB::table('meta_events')->count());
    }

    // --- the batch ---------------------------------------------------------

    private function viewEvent(string $id): void
    {
        $this->postJson('/api/frontend/track', [
            'event' => 'ViewContent', 'event_id' => $id, 'product_id' => $this->product->id,
        ])->assertNoContent();
    }

    public function test_pending_events_go_to_meta_in_one_batch(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 2])]);
        $this->viewEvent('a-1');
        $this->viewEvent('a-2');

        $this->artisan('meta:send-events')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) {
            return str_contains($request->url(), '/1234567890123456/events')
                // The token travels in the body, never in a loggable URL.
                && !str_contains($request->url(), 'test-token')
                && $request['access_token'] === 'test-token'
                && count($request['data']) === 2;
        });

        $this->assertSame(2, DB::table('meta_events')->whereNotNull('sent_at')->count());

        // Already sent: the next run sends nothing.
        $this->artisan('meta:send-events')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_events_wait_when_meta_is_down(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response('unavailable', 503)]);
        $this->viewEvent('b-1');

        $this->artisan('meta:send-events')->assertSuccessful();

        $this->assertSame(1, DB::table('meta_events')->whereNull('sent_at')->count(), 'kept for the next minute');
    }

    public function test_one_bad_event_cannot_block_the_rest(): void
    {
        $this->viewEvent('good-1');
        $this->viewEvent('bad-1');
        $this->viewEvent('good-2');

        // Meta rejects a whole batch over one bad event; singly, only it fails.
        Http::fake(function (HttpRequest $request) {
            $ids = array_column($request['data'], 'event_id');

            return in_array('bad-1', $ids, true)
                ? Http::response(['error' => ['message' => 'Invalid parameter']], 400)
                : Http::response(['events_received' => count($ids)]);
        });

        $this->assertSame(2, app(MetaConversionsService::class)->sendPending());
        $this->assertSame(0, DB::table('meta_events')->whereNull('sent_at')->count(), 'the bad one is dropped, not retried forever');
    }

    public function test_old_events_are_pruned(): void
    {
        $this->viewEvent('old-unsent');
        $this->viewEvent('old-sent');
        DB::table('meta_events')->where('event_id', 'old-unsent')->update(['created_at' => now()->subDays(8)]);
        DB::table('meta_events')->where('event_id', 'old-sent')->update(['sent_at' => now()->subDays(2)]);

        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 0])]);
        $this->artisan('meta:send-events')->assertSuccessful();

        // Older than Meta accepts: never sent, just removed.
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('meta_events')->count());
    }
}
