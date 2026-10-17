<?php

namespace Tests\Feature;

use App\Enums\Activity;
use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Models\Currency;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Support\PaymentLink;
use Dipokhalder\Settings\Facades\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The server page every order passes through between Confirm Order and the
 * order page (/payment/{gateway}/pay/{order}). For cash on delivery it sends
 * its form at once, so the customer only sees it for a moment - and what they
 * see has to be the same "Placing your order..." card as the screens either
 * side of it, not a different page.
 */
class PaymentPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The page as production renders it, from public/build. A Vite dev
        // server left running in this folder writes public/hot, and @vite then
        // prints its .../resources/css/app.css URL - which reads exactly like
        // the broken css/app.css link this test guards against.
        \Illuminate\Support\Facades\Vite::useHotFile(storage_path('framework/testing/no-vite-dev-server'));
    }

    private function openPaymentPage(): TestResponse
    {
        // Only the settings this page reads, set directly. Not the install
        // seeders: Site/Company/CurrencyTableSeeder write into the real .env
        // through the env editor (CURRENCY=USD, APP_NAME, ...) and run
        // optimize:clear - a test must never do either.
        $currency = Currency::create(['name' => 'Taka', 'symbol' => '৳', 'code' => 'BDT', 'is_cryptocurrency' => Ask::NO, 'exchange_rate' => 1]);
        Settings::group('site')->set(['site_default_currency' => $currency->id, 'site_cash_on_delivery' => Activity::ENABLE]);
        Settings::group('company')->set(['company_name' => 'SUGLOW']);
        Settings::group('theme')->set(['theme_logo' => '', 'theme_favicon_logo' => '', 'theme_footer_logo' => '']);

        PaymentGateway::create(['name' => 'Cash On Delivery', 'slug' => 'cashondelivery', 'status' => Activity::ENABLE]);

        $customer = User::create([
            'name' => 'Rima', 'username' => 'rima', 'email' => 'rima@example.com', 'password' => bcrypt('secret123'),
            'phone' => '01711111111', 'country_code' => '+880', 'is_guest' => Ask::NO, 'status' => Status::ACTIVE,
        ]);

        $order = Order::create([
            'user_id' => $customer->id, 'order_type' => OrderType::DELIVERY, 'subtotal' => 500, 'total' => 500,
            'discount' => 0, 'tax' => 0, 'shipping_charge' => 0, 'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID, 'source' => 5, 'payment_method' => 1, 'active' => Ask::NO,
            'order_datetime' => now(),
        ]);

        return $this->get("/payment/cashondelivery/pay/{$order->id}?token=" . PaymentLink::token($order->id));
    }

    public function test_it_shows_the_same_placing_your_order_card_as_the_checkout(): void
    {
        $response = $this->openPaymentPage();

        $response->assertOk();
        $response->assertSee('id="loading-show" class="ob ob--static', false);
        $response->assertSee('Placing your order…', false);
        $response->assertSee('data-bn="আপনার অর্ডার দেওয়া হচ্ছে…"', false);
    }

    public function test_it_loads_the_shops_stylesheets_instead_of_a_missing_one(): void
    {
        $response = $this->openPaymentPage();

        $response->assertOk();
        // css/app.css does not exist in this build; its 404 is what left the
        // hidden parts of this page showing.
        $response->assertDontSee('/css/app.css"', false);
        $response->assertSee('/build/assets/', false);
        // An 870 KB GIF fetched for a page shown for under a second.
        $response->assertDontSee('payment-loading.gif', false);
    }
}
