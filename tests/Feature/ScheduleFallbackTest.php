<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Product;
use App\Models\User;
use App\Services\MetaConversionsService;
use App\Support\ScheduleFallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The storefront keeps the Meta outbox moving when the server's cron is not
 * running - and stands down when it is.
 */
class ScheduleFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta_pixel.id'    => '1234567890123456',
            'services.meta_pixel.token' => 'test-token',
        ]);

        // Only the Meta job is under test: the feed and the sitemap would
        // write into public/ from here.
        Cache::put('schedule-fallback:product-feed', time(), 3600);
        Cache::put('schedule-fallback:sitemap', time(), 3600);

        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
    }

    private function queueEvent(string $id = 'evt-1'): void
    {
        app(MetaConversionsService::class)->queue('ViewContent', $id, ['client_ip_address' => '103.1.1.1'], ['value' => 1]);
    }

    public function test_pending_events_are_sent_when_the_cron_is_silent(): void
    {
        $this->queueEvent();

        ScheduleFallback::runDue();

        Http::assertSentCount(1);
        $this->assertNotNull(DB::table('meta_events')->value('sent_at'));
    }

    public function test_it_runs_at_most_once_a_minute(): void
    {
        $this->queueEvent('evt-1');
        ScheduleFallback::runDue();

        $this->queueEvent('evt-2');
        ScheduleFallback::runDue();

        // The second event waits for the next minute's run.
        Http::assertSentCount(1);
        $this->assertNull(DB::table('meta_events')->where('event_id', 'evt-2')->value('sent_at'));
    }

    public function test_it_stands_down_while_the_cron_is_alive(): void
    {
        ScheduleFallback::heartbeat();
        $this->queueEvent();

        ScheduleFallback::runDue();

        Http::assertNothingSent();
    }

    public function test_the_meta_address_is_the_shoppers_not_the_cdns(): void
    {
        $request = Request::create('/api/frontend/track', 'POST', [], [], [], [
            'REMOTE_ADDR'          => '10.0.0.7',
            'HTTP_X_FORWARDED_FOR' => '192.168.1.4, 103.120.5.9, 172.16.0.2',
        ]);

        $data = app(MetaConversionsService::class)->userData(null, $request);

        // The first public address; private hops and the edge are skipped.
        $this->assertSame('103.120.5.9', $data['client_ip_address']);
    }

    public function test_without_a_forwarded_header_the_connection_address_is_used(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '103.7.7.7']);

        $this->assertSame('103.7.7.7', app(MetaConversionsService::class)->userData(null, $request)['client_ip_address']);
    }

    public function test_wishlist_and_payment_info_events_are_mirrored(): void
    {
        $product = Product::create([
            'name' => 'Serum', 'slug' => 'serum', 'sku' => 'SERUM1', 'status' => Status::ACTIVE,
            'can_purchasable' => Ask::YES, 'buying_price' => 100, 'selling_price' => 500, 'variation_price' => 500,
        ]);

        $this->postJson('/api/frontend/track', ['event' => 'AddToWishlist', 'event_id' => 'w-1', 'product_id' => $product->id])
            ->assertNoContent();
        $this->postJson('/api/frontend/track', [
            'event' => 'AddPaymentInfo', 'event_id' => 'p-1', 'contents' => [['id' => $product->id, 'quantity' => 2]],
        ])->assertNoContent();

        $payment = json_decode(DB::table('meta_events')->where('event_id', 'p-1')->value('payload'), true);
        $this->assertSame(1000.0, (float) $payment['custom_data']['value']);
        $this->assertSame('AddToWishlist', DB::table('meta_events')->where('event_id', 'w-1')->value('event_name'));
    }

    public function test_a_new_account_is_reported_once(): void
    {
        $user = User::create([
            'name' => 'Rima Akter', 'username' => 'rima', 'phone' => '1711111111', 'country_code' => '+880',
            'is_guest' => Ask::NO, 'status' => Status::ACTIVE, 'password' => bcrypt('x'),
        ]);

        $meta = app(MetaConversionsService::class);
        $this->assertTrue($meta->completeRegistration($user, request()));
        $this->assertFalse($meta->completeRegistration($user, request()));

        $this->assertSame(1, DB::table('meta_events')->where('event_name', 'CompleteRegistration')->count());
    }
}
