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
use App\Services\TikTokEventsService;
use App\Support\ScheduleFallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TikTok's Events API: the browser pixel's events sent again from the server,
 * on the same rules as Meta's Conversions API (MetaConversionsTest) - priced
 * from the database, Purchase only from the order, stored during the request
 * and sent by the per-minute batch.
 */
class TikTokEventsTest extends TestCase
{
    use RefreshDatabase;

    private const PIXEL_ID = 'DAS4C9RC77U88MSO82I0';

    private const ENDPOINT = 'https://business-api.tiktok.com/open_api/v1.3/event/track/';

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // TikTok alone: it must not depend on Meta being configured.
        config([
            'services.tiktok_pixel.id'    => self::PIXEL_ID,
            'services.tiktok_pixel.token' => 'tt-token',
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
            'phone' => '01711111111', 'country_code' => '+880', 'is_guest' => Ask::NO, 'status' => Status::ACTIVE,
        ]);
        $user->assignRole(EnumRole::CUSTOMER);

        return $user;
    }

    /** Placed the way FrontendOrderService places one: the order row first, then its lines and address. */
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
        return DB::table('tiktok_events')->orderBy('id')->get()
            ->map(fn($row) => json_decode($row->payload, true) + ['_ready' => (bool) $row->ready, '_sent' => $row->sent_at !== null])
            ->all();
    }

    private function viewEvent(string $id): void
    {
        $this->postJson('/api/frontend/track', [
            'event' => 'ViewContent', 'event_id' => $id, 'product_id' => $this->product->id,
        ])->assertNoContent();
    }

    private function pending(): int
    {
        return DB::table('tiktok_events')->whereNull('sent_at')->count();
    }

    // --- the browser mirror ------------------------------------------------

    public function test_a_product_view_is_stored_in_tiktoks_shape_priced_from_the_database(): void
    {
        Http::fake();

        $this->postJson('/api/frontend/track', [
            'event'      => 'ViewContent',
            'event_id'   => 'abc-123',
            'product_id' => $this->product->id,
            'source_url' => url('/product/serum?ttclid=E.C.P.abc&q=acne'),
            // A crafted request trying to report a huge value.
            'value'      => 999999,
        ])->assertNoContent();

        $event = $this->stored()[0];
        $this->assertSame('ViewContent', $event['event']);
        $this->assertSame('abc-123', $event['event_id'], 'the browser\'s id must be kept, or TikTok counts the event twice');
        $this->assertSame([[
            'content_id' => (string) $this->product->id, 'content_type' => 'product', 'quantity' => 1, 'price' => 500,
        ]], $event['properties']['contents']);
        $this->assertEquals(500, $event['properties']['value']);
        $this->assertSame('BDT', $event['properties']['currency']);
        $this->assertSame(url('/product/serum'), $event['page']['url'], 'no query string: click ids and search terms stay out');
        $this->assertStringNotContainsString('Acne', json_encode($event));

        // Stored, not sent, and Meta (not configured here) gets nothing.
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('meta_events')->count());
    }

    public function test_it_runs_beside_meta_with_the_same_event_id(): void
    {
        config(['services.meta_pixel.id' => '1234567890123456', 'services.meta_pixel.token' => 'meta-token']);

        $this->viewEvent('both-1');

        $this->assertSame('both-1', DB::table('tiktok_events')->value('event_id'));
        $this->assertSame('both-1', DB::table('meta_events')->value('event_id'));
    }

    public function test_nothing_is_stored_without_an_access_token(): void
    {
        config(['services.tiktok_pixel.token' => null]);

        $this->viewEvent('abc-124');

        $this->assertSame(0, DB::table('tiktok_events')->count());
    }

    /** Personal details leave only hashed, normalised the way TikTok matches them. */
    public function test_customer_details_are_normalised_and_hashed(): void
    {
        $user = $this->customer();
        Sanctum::actingAs($user);

        $this->withCredentials()
            ->withUnencryptedCookie('_ttp', '2mQ8bXyZabc123DEF456ghi789')
            ->withUnencryptedCookie('suglow_ttclid', 'E.C.P.CqwBabc123-XYZ_789')
            ->withHeader('X-Forwarded-For', '103.7.7.7, 10.0.0.1')
            ->postJson('/api/frontend/track', [
                'event' => 'ViewContent', 'event_id' => 'abc-125', 'product_id' => $this->product->id,
            ])->assertNoContent();

        $data = $this->stored()[0]['user'];
        $serialised = json_encode($data);

        $this->assertStringNotContainsString('example.com', strtolower($serialised));
        $this->assertStringNotContainsString('1711111111', $serialised);
        $this->assertSame(hash('sha256', 'rima@example.com'), $data['email']);
        $this->assertSame(hash('sha256', '+8801711111111'), $data['phone'], 'E.164, with the plus');
        $this->assertSame(hash('sha256', (string) $user->id), $data['external_id']);
        $this->assertSame('2mQ8bXyZabc123DEF456ghi789', $data['ttp']);
        $this->assertSame('E.C.P.CqwBabc123-XYZ_789', $data['ttclid']);
        $this->assertSame('103.7.7.7', $data['ip']);
    }

    public function test_a_malformed_cookie_is_not_forwarded(): void
    {
        $this->withCredentials()
            ->withUnencryptedCookie('_ttp', '<script>')
            ->withUnencryptedCookie('suglow_ttclid', 'x')
            ->postJson('/api/frontend/track', [
                'event' => 'ViewContent', 'event_id' => 'abc-126', 'product_id' => $this->product->id,
            ])->assertNoContent();

        $data = $this->stored()[0]['user'];
        $this->assertArrayNotHasKey('ttp', $data);
        $this->assertArrayNotHasKey('ttclid', $data);
    }

    // --- the purchase, from the order --------------------------------------

    public function test_a_cash_on_delivery_order_is_reported_as_a_purchase(): void
    {
        $order = $this->order();

        $events = $this->stored();
        $this->assertCount(1, $events);
        $event = $events[0];

        $this->assertSame('Purchase', $event['event']);
        // The browser pixel uses the same id for this order, so TikTok keeps one.
        $this->assertSame('order-' . $order->id, $event['event_id']);
        $this->assertTrue($event['_ready']);
        $this->assertEquals(1095, $event['properties']['value']);
        $this->assertSame((string) $order->id, $event['properties']['order_id']);
        $this->assertSame([[
            'content_id' => (string) $this->product->id, 'content_type' => 'product', 'quantity' => 2, 'price' => 500,
        ]], $event['properties']['contents']);
    }

    public function test_an_online_payment_is_held_until_it_is_paid(): void
    {
        $order = $this->order(Source::WEB, OrderType::DELIVERY, 7);

        $this->assertFalse($this->stored()[0]['_ready'], 'an unpaid online order is not a sale');

        Http::fake(['business-api.tiktok.com/*' => Http::response(['code' => 0, 'message' => 'OK'])]);
        app(TikTokEventsService::class)->sendPending();
        Http::assertNothingSent();

        $order->update(['payment_status' => PaymentStatus::PAID]);

        $this->assertTrue($this->stored()[0]['_ready']);
        $this->assertSame(1, app(TikTokEventsService::class)->sendPending());
    }

    public function test_a_till_order_is_not_reported(): void
    {
        $this->order(Source::POS, OrderType::POS);

        $this->assertSame(0, DB::table('tiktok_events')->count());
    }

    public function test_a_sign_up_is_reported_once(): void
    {
        $user = $this->customer();
        $tiktok = app(TikTokEventsService::class);

        $this->assertTrue($tiktok->completeRegistration($user, request()));
        $this->assertFalse($tiktok->completeRegistration($user, request()));

        $this->assertSame('registration-' . $user->id, DB::table('tiktok_events')->value('event_id'));
    }

    // --- the batch ---------------------------------------------------------

    public function test_pending_events_go_to_tiktok_in_one_batch(): void
    {
        Http::fake(['business-api.tiktok.com/*' => Http::response(['code' => 0, 'message' => 'OK'])]);
        $this->viewEvent('a-1');
        $this->viewEvent('a-2');

        $this->artisan('tiktok:send-events')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) {
            return $request->url() === self::ENDPOINT
                && $request->header('Access-Token') === ['tt-token']
                // The token travels in a header, never in the body or a loggable URL.
                && !str_contains($request->body(), 'tt-token')
                && $request['event_source'] === 'web'
                && $request['event_source_id'] === self::PIXEL_ID
                && count($request['data']) === 2;
        });

        $this->assertSame(0, $this->pending());

        // Already sent: the next run sends nothing.
        $this->artisan('tiktok:send-events')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_events_wait_when_tiktok_is_down(): void
    {
        $this->viewEvent('b-1');
        $this->viewEvent('b-2');

        Http::fake(['business-api.tiktok.com/*' => Http::response('unavailable', 503)]);
        $this->artisan('tiktok:send-events')->assertSuccessful();

        // TikTok also reports its own failures as HTTP 200 with a 5xxxx code.
        Http::fake(['business-api.tiktok.com/*' => Http::response(['code' => 50000, 'message' => 'System error'])]);
        $this->artisan('tiktok:send-events')->assertSuccessful();

        $this->assertSame(2, $this->pending(), 'kept for the next minute');
    }

    public function test_one_bad_event_cannot_block_the_rest(): void
    {
        $this->viewEvent('good-1');
        $this->viewEvent('bad-1');
        $this->viewEvent('good-2');

        // TikTok refuses a whole batch over one bad event - with HTTP 200 and
        // a non-zero code. Sent singly, only that one fails.
        Http::fake(function (HttpRequest $request) {
            $ids = array_column($request['data'], 'event_id');

            return Http::response(in_array('bad-1', $ids, true)
                ? ['code' => 40002, 'message' => 'Invalid parameter']
                : ['code' => 0, 'message' => 'OK']);
        });

        $this->assertSame(2, app(TikTokEventsService::class)->sendPending());
        $this->assertSame(0, $this->pending(), 'the bad one is dropped, not retried forever');
    }

    /**
     * A refusal of every event is a problem with the request itself - a wrong
     * or revoked token - not with the events. They wait for it to be fixed,
     * and TikTok is not called once per event every minute meanwhile.
     */
    public function test_a_wrong_token_keeps_every_event_and_does_not_flood_tiktok(): void
    {
        foreach (range(1, 5) as $i) {
            $this->viewEvent("t-{$i}");
        }

        Http::fake(['business-api.tiktok.com/*' => Http::response(['code' => 40105, 'message' => 'Access token is invalid'])]);

        $this->assertSame(0, app(TikTokEventsService::class)->sendPending());
        $this->assertSame(5, $this->pending());
        Http::assertSentCount(3);
    }

    public function test_old_events_are_pruned(): void
    {
        $this->viewEvent('old-unsent');
        $this->viewEvent('old-sent');
        DB::table('tiktok_events')->where('event_id', 'old-unsent')->update(['created_at' => now()->subDays(8)]);
        DB::table('tiktok_events')->where('event_id', 'old-sent')->update(['sent_at' => now()->subDays(2)]);

        Http::fake();
        $this->artisan('tiktok:send-events')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('tiktok_events')->count());
    }

    public function test_the_storefront_sends_them_when_the_cron_is_silent(): void
    {
        Cache::put('schedule-fallback:product-feed', time(), 3600);
        Cache::put('schedule-fallback:sitemap', time(), 3600);
        Http::fake(['business-api.tiktok.com/*' => Http::response(['code' => 0, 'message' => 'OK'])]);
        $this->viewEvent('fb-1');

        ScheduleFallback::runDue();

        $this->assertSame(0, $this->pending());
    }

    // --- the ad click id ---------------------------------------------------

    private function cookie($response, string $name): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    /** A visitor from a TikTok ad keeps its click id even when the pixel is blocked. */
    public function test_an_ad_click_id_is_kept_for_the_server(): void
    {
        $response = $this->get('/?ttclid=E.C.P.CqwBabc123-XYZ_789')->assertOk();

        $cookie = $this->cookie($response, 'suglow_ttclid');
        $this->assertNotNull($cookie);
        $this->assertSame('E.C.P.CqwBabc123-XYZ_789', $cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly(), 'only the server reads it');
    }

    public function test_no_click_id_cookie_without_a_tiktok_pixel(): void
    {
        config(['services.tiktok_pixel.id' => null]);

        $response = $this->get('/?ttclid=E.C.P.CqwBabc123-XYZ_789')->assertOk();

        $this->assertNull($this->cookie($response, 'suglow_ttclid'));
    }
}
