<?php

namespace Tests\Feature;

use App\Enums\Activity;
use App\Enums\Ask;
use App\Enums\GatewayMode;
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
use App\Models\GatewayOption;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Transaction;
use App\Models\User;
use App\Support\PaymentLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Karim007\LaravelBkashTokenize\Facade\BkashPaymentTokenize;
use Tests\TestCase;

/**
 * bKash callbacks and the unauthenticated payment page.
 *
 * The bKash paymentID arrives on the callback URL, so a customer could start a
 * cheap order's payment and send its paymentID to an expensive order's success
 * URL - bKash reported "Completed" and the expensive order was marked paid.
 */
class PaymentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([SendOrderMail::class, SendOrderSms::class, SendOrderPush::class,
            SendOrderGotMail::class, SendOrderGotSms::class, SendOrderGotPush::class]);

        $this->customer = User::create([
            'name' => 'Rima', 'username' => 'rima', 'email' => 'rima@example.com', 'password' => bcrypt('secret123'),
            'phone' => '01711111111', 'country_code' => '+880', 'is_guest' => Ask::NO, 'status' => Status::ACTIVE,
        ]);

        foreach (['cashondelivery' => 'Cash On Delivery', 'bkash' => 'Bkash'] as $slug => $name) {
            $gateway = PaymentGateway::create(['name' => $name, 'slug' => $slug, 'status' => Activity::ENABLE]);
            foreach (['bkash_mode' => GatewayMode::SANDBOX, 'bkash_app_key' => 'k', 'bkash_app_secret' => 's',
                         'bkash_username' => 'u', 'bkash_password' => 'p'] as $option => $value) {
                GatewayOption::create([
                    'model_id' => $gateway->id, 'model_type' => PaymentGateway::class,
                    'option' => $option, 'value' => $value, 'type' => 5, 'activities' => '',
                ]);
            }
        }
    }

    private function order(float $total): Order
    {
        $order = Order::create([
            'user_id' => $this->customer->id, 'order_type' => OrderType::DELIVERY, 'subtotal' => $total, 'total' => $total,
            'discount' => 0, 'tax' => 0, 'shipping_charge' => 0, 'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID, 'source' => 5, 'payment_method' => 3, 'active' => Ask::NO,
            'order_datetime' => now(),
        ]);
        $order->update(['order_serial_no' => '210926' . $order->id]);

        return $order->fresh();
    }

    private function bkashSays(array $response): void
    {
        BkashPaymentTokenize::shouldReceive('executePayment')->andReturn($response + [
            'statusCode' => '0000', 'transactionStatus' => 'Completed', 'paymentID' => 'PAY1',
        ]);
    }

    private function bkashCallback(Order $order): void
    {
        $this->get("/payment/bkash/{$order->id}/success?status=success&paymentID=PAY1");
    }

    public function test_a_matching_bkash_payment_settles_the_order(): void
    {
        $order = $this->order(5000);
        $this->bkashSays(['amount' => '5000', 'merchantInvoiceNumber' => $order->order_serial_no, 'trxID' => 'TRX1']);

        $this->bkashCallback($order);

        $this->assertSame(PaymentStatus::PAID, (int) $order->fresh()->payment_status);
    }

    public function test_a_cheaper_bkash_payment_cannot_settle_a_dearer_order(): void
    {
        $cheap = $this->order(100);
        $dear  = $this->order(5000);
        $this->bkashSays(['amount' => '100', 'merchantInvoiceNumber' => $cheap->order_serial_no, 'trxID' => 'TRX2']);

        $this->bkashCallback($dear);

        $this->assertSame(PaymentStatus::UNPAID, (int) $dear->fresh()->payment_status);
    }

    public function test_a_payment_for_another_invoice_cannot_settle_this_order(): void
    {
        $first  = $this->order(5000);
        $second = $this->order(5000);
        $this->bkashSays(['amount' => '5000', 'merchantInvoiceNumber' => $first->order_serial_no, 'trxID' => 'TRX3']);

        $this->bkashCallback($second);

        $this->assertSame(PaymentStatus::UNPAID, (int) $second->fresh()->payment_status);
    }

    public function test_one_bkash_transaction_cannot_settle_two_orders(): void
    {
        $first  = $this->order(5000);
        $second = $this->order(5000);
        Transaction::create([
            'order_id' => $first->id, 'transaction_no' => 'TRX4', 'amount' => 5000, 'payment_method' => 'bkash',
            'sign' => '+', 'type' => 'payment', 'user_id' => $this->customer->id,
        ]);
        // No invoice in the response, as on a query fallback: the trxID alone
        // must stop the replay.
        $this->bkashSays(['amount' => '5000', 'trxID' => 'TRX4']);

        $this->bkashCallback($second);

        $this->assertSame(PaymentStatus::UNPAID, (int) $second->fresh()->payment_status);
    }

    // --- the payment page -------------------------------------------------

    public function test_the_payment_page_needs_the_order_token(): void
    {
        $order = $this->order(500);

        $this->get("/payment/cashondelivery/pay/{$order->id}")->assertRedirect(route('home'));
        $this->get("/payment/cashondelivery/pay/{$order->id}?token=guess")->assertRedirect(route('home'));
    }

    public function test_the_order_token_opens_the_payment_page_for_this_browser(): void
    {
        $order = $this->order(500);

        $this->get("/payment/cashondelivery/pay/{$order->id}?token=" . PaymentLink::token($order->id));

        $this->assertTrue(session()->get(PaymentLink::sessionKey($order->id)));
    }

    public function test_a_token_for_one_order_does_not_open_another(): void
    {
        $mine   = $this->order(500);
        $theirs = $this->order(900);

        $this->get("/payment/cashondelivery/pay/{$theirs->id}?token=" . PaymentLink::token($mine->id))
            ->assertRedirect(route('home'));
    }

    public function test_an_unknown_payment_method_is_refused_without_an_error_page(): void
    {
        $order = $this->order(500);
        $this->get("/payment/cashondelivery/pay/{$order->id}?token=" . PaymentLink::token($order->id));

        $this->post("/payment/{$order->id}/pay", ['paymentMethod' => 'Nonexistent'])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertSame(PaymentStatus::UNPAID, (int) $order->fresh()->payment_status);
    }

    public function test_an_order_cannot_be_paid_from_a_browser_that_never_opened_it(): void
    {
        $order = $this->order(500);

        $this->post("/payment/{$order->id}/pay", ['paymentMethod' => 'cashondelivery'])->assertRedirect(route('home'));
    }
}
