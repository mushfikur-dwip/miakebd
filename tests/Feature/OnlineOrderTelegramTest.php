<?php

namespace Tests\Feature;

use App\Enums\Activity;
use App\Enums\Ask;
use App\Enums\AddressType;
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
use App\Libraries\AppLibrary;
use App\Models\CapturePaymentNotification;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Services\OnlineOrderTelegramNotificationService;
use App\Services\PosOrderTelegramNotificationService;
use Dipokhalder\Settings\Facades\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as TelegramRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A confirmed online order is announced in the shop's Telegram chat, the same
 * chat the POS alerts go to, once per order.
 */
class OnlineOrderTelegramTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://suglow.com']);

        // The shop's other new-order notifications: not under test, never sent.
        Event::fake([SendOrderMail::class, SendOrderSms::class, SendOrderPush::class,
            SendOrderGotMail::class, SendOrderGotSms::class, SendOrderGotPush::class]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        Settings::group('telegram')->set([
            'telegram_status'    => Ask::YES,
            'telegram_bot_token' => 'TEST-TOKEN',
            'telegram_chat_id'   => '-100123',
        ]);

        PaymentGateway::create(['name' => 'Cash On Delivery', 'slug' => 'cashondelivery', 'status' => Activity::ENABLE]);
    }

    private function customer(): User
    {
        return User::create([
            'name' => 'Rima Akter', 'username' => 'rima' . uniqid(), 'phone' => '1711111111', 'country_code' => '+880',
            'is_guest' => Ask::YES, 'status' => Status::ACTIVE, 'password' => bcrypt('x'),
        ]);
    }

    private function order(array $attributes = []): Order
    {
        $order = Order::create($attributes + [
            'user_id' => $this->customer()->id, 'order_type' => OrderType::DELIVERY, 'subtotal' => 1000, 'total' => 1120,
            'discount' => 0, 'tax' => 0, 'shipping_charge' => 120, 'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID, 'source' => 5, 'payment_method' => 1, 'active' => Ask::YES,
            'order_datetime' => now(),
        ]);
        $order->update(['order_serial_no' => '071026' . $order->id]);

        return $order->fresh();
    }

    private function item(Order $order, string $name, int $quantity, ?string $variation = null): void
    {
        $n = ++$this->sequence;
        $product = Product::create([
            'name' => $name, 'slug' => 'product-' . $n, 'sku' => 'SKU' . $n, 'status' => Status::ACTIVE,
            'can_purchasable' => Ask::YES, 'buying_price' => 100, 'selling_price' => 500, 'variation_price' => 500,
        ]);

        Stock::create([
            'product_id' => $product->id, 'model_type' => Order::class, 'model_id' => $order->id,
            'item_type' => Product::class, 'item_id' => $product->id, 'variation_names' => $variation,
            'price' => 500, 'quantity' => -$quantity, 'discount' => 0, 'tax' => 0, 'sku' => 'SKU' . $n,
            'status' => Status::ACTIVE, 'subtotal' => 500 * $quantity, 'total' => 500 * $quantity,
        ]);
    }

    private function shipTo(Order $order): void
    {
        OrderAddress::create([
            'order_id' => $order->id, 'user_id' => $order->user_id, 'address_type' => AddressType::SHIPPING,
            // Stored as AddressRequest leaves it: no leading 0.
            'full_name' => 'Rima Akter', 'phone' => '1711111111', 'country_code' => '+880',
            'address' => 'House 12, Road 3', 'city' => 'Rangpur Sadar', 'state' => 'Rangpur',
        ]);
    }

    /** @return list<array> the messages posted to Telegram */
    private function messages(): array
    {
        return Http::recorded()->map(fn ($pair) => ['url' => $pair[0]->url()] + $pair[0]->data())->values()->all();
    }

    public function test_a_confirmed_online_order_is_announced_in_the_shop_chat(): void
    {
        $order = $this->order();
        $this->shipTo($order);
        $this->item($order, 'CeraVe Moisturising Cream', 1);
        $this->item($order, 'Dove Body Wash & Lotion', 2, 'Size: 250ml');

        $this->get('/payment/successful/' . $order->id)->assertRedirect();

        $sent = $this->messages();
        $this->assertCount(1, $sent);
        $this->assertStringEndsWith('/botTEST-TOKEN/sendMessage', $sent[0]['url']);
        $this->assertSame('-100123', $sent[0]['chat_id']);
        $this->assertSame('HTML', $sent[0]['parse_mode']);

        $text = $sent[0]['text'];
        $this->assertStringContainsString('<b>New Online Order</b>', $text);
        $this->assertStringContainsString('Order No: 071026' . $order->id, $text);
        // As the admin order page writes it.
        $this->assertStringContainsString('Customer: Rima Akter - +8801711111111', $text);
        $this->assertStringContainsString('Delivery: House 12, Road 3, Rangpur Sadar, Rangpur', $text);
        $this->assertStringContainsString('CeraVe Moisturising Cream x 1', $text);
        // Escaped, or Telegram refuses the whole message as broken HTML.
        $this->assertStringContainsString('Dove Body Wash &amp; Lotion (Size: 250ml) x 2', $text);
        $this->assertStringContainsString('Total: ' . AppLibrary::currencyAmountFormat(1120), $text);
        $this->assertStringContainsString('Payment: Cash On Delivery - Unpaid', $text);
        $this->assertStringContainsString('https://suglow.com/admin/online-orders/show/' . $order->id, $text);
    }

    public function test_each_order_is_announced_only_once(): void
    {
        $order = $this->order();

        $this->get('/payment/successful/' . $order->id);
        $this->get('/payment/successful/' . $order->id);
        // Even when the alert is asked for again directly.
        app(OnlineOrderTelegramNotificationService::class)->send($order->id);

        $this->assertCount(1, $this->messages());
    }

    /**
     * Cash on delivery the way checkout runs it: Place Order creates the order
     * inactive, the payment page submits itself, and the gateway's success
     * step commits it on the way to the confirmation page. The alert is out
     * then - with the order still Pending, before anyone in the shop has
     * pressed Confirm.
     */
    public function test_a_cash_on_delivery_order_is_announced_as_soon_as_it_is_placed(): void
    {
        $order = $this->order(['active' => Ask::NO]);
        CapturePaymentNotification::create(['order_id' => $order->id, 'token' => '123456789', 'created_at' => now()]);

        $this->get('/payment/cashondelivery/' . $order->id . '/success?token=123456789')
            ->assertRedirectContains('/payment/successful/' . $order->id);
        $this->get('/payment/successful/' . $order->id);

        $this->assertCount(1, $this->messages());
        $this->assertSame(OrderStatus::PENDING, (int) $order->fresh()->status);
    }

    /** A mail server that is down must not keep the order from the shop's chat. */
    public function test_a_failing_order_email_does_not_hold_back_the_alert(): void
    {
        // The customer's confirmation email reaches a listener that fails the
        // way an unreachable SMTP server does; the rest stay faked.
        Event::fake([SendOrderSms::class, SendOrderPush::class, SendOrderGotMail::class, SendOrderGotSms::class, SendOrderGotPush::class]);
        Event::getFacadeRoot()->dispatcher->forget(SendOrderMail::class);
        Event::listen(SendOrderMail::class, fn () => throw new \RuntimeException('Connection could not be established with host smtp'));

        $order = $this->order();
        $this->get('/payment/successful/' . $order->id)->assertRedirect();

        $this->assertCount(1, $this->messages());
    }

    public function test_a_long_order_lists_ten_items_and_counts_the_rest(): void
    {
        $order = $this->order();
        for ($i = 1; $i <= 12; $i++) {
            $this->item($order, 'Item ' . $i, 1);
        }

        app(OnlineOrderTelegramNotificationService::class)->send($order->id);

        $text = $this->messages()[0]['text'];
        $this->assertStringContainsString('Item 10 x 1', $text);
        $this->assertStringNotContainsString('Item 11 x 1', $text);
        $this->assertStringContainsString('+2 more', $text);
    }

    public function test_a_pickup_order_names_the_outlet(): void
    {
        $outlet = \App\Models\Outlet::create(['name' => 'RAMC Outlet', 'email' => 'ramc@example.com', 'phone' => '01700000000', 'country_code' => '+880', 'city' => 'Rangpur', 'state' => 'Rangpur', 'zip_code' => '5400', 'address' => 'RAMC', 'status' => Status::ACTIVE]);
        $order = $this->order(['order_type' => OrderType::PICK_UP, 'outlet_id' => $outlet->id]);

        app(OnlineOrderTelegramNotificationService::class)->send($order->id);

        $this->assertStringContainsString('Pickup: RAMC Outlet', $this->messages()[0]['text']);
    }

    public function test_nothing_is_sent_while_telegram_is_switched_off(): void
    {
        Settings::group('telegram')->set(['telegram_status' => Ask::NO]);

        app(OnlineOrderTelegramNotificationService::class)->send($this->order()->id);

        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_without_a_bot_token(): void
    {
        Settings::group('telegram')->set(['telegram_bot_token' => '']);

        app(OnlineOrderTelegramNotificationService::class)->send($this->order()->id);

        Http::assertNothingSent();
    }

    /**
     * Paid from the wallet: the confirmation page itself commits the order,
     * and the checkout may still carry a stale gateway choice - the courier
     * must not be told to collect cash.
     */
    public function test_a_wallet_paid_order_is_announced_as_paid_from_the_wallet(): void
    {
        $order = $this->order(['active' => Ask::NO, 'wallet_discount' => 1120]);

        $this->get('/payment/successful/' . $order->id);

        $this->assertStringContainsString('Payment: Wallet - Paid', $this->messages()[0]['text']);
    }

    /**
     * The confirmation page opens for any order id. A checkout nobody went
     * through with - still at the gateway, or abandoned - is not an order.
     */
    public function test_an_abandoned_checkout_is_not_announced(): void
    {
        $order = $this->order(['active' => Ask::NO]);

        $this->get('/payment/successful/' . $order->id);

        Http::assertNothingSent();
    }

    public function test_a_pos_sale_is_not_announced_as_an_online_order(): void
    {
        $sale = $this->order(['order_type' => OrderType::POS, 'payment_status' => PaymentStatus::PAID]);

        app(OnlineOrderTelegramNotificationService::class)->send($sale->id);

        Http::assertNothingSent();
    }

    /** The POS alert shares the sending code; it must keep working as before. */
    public function test_the_pos_alert_still_goes_out(): void
    {
        $sale = $this->order(['order_type' => OrderType::POS, 'payment_status' => PaymentStatus::PAID]);

        app(PosOrderTelegramNotificationService::class)->send($sale->id);

        $sent = $this->messages();
        $this->assertCount(1, $sent);
        $this->assertSame('-100123', $sent[0]['chat_id']);
        $this->assertStringContainsString('<b>New POS Order</b>', $sent[0]['text']);
        $this->assertStringContainsString('Order No: 071026' . $sale->id, $sent[0]['text']);
    }
}
