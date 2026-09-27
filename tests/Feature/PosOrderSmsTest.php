<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\SwitchBox;
use App\Events\SendPosOrderSms;
use App\Events\SendPosOrderTelegram;
use App\Http\Requests\PosOrderRequest;
use App\Models\Outlet;
use App\Models\Product;
use App\Services\OrderService;
use Illuminate\Support\Facades\Event;
use App\Libraries\AppLibrary;
use App\Models\NotificationAlert;
use App\Models\Order;
use App\Models\User;
use App\Services\PosOrderSmsNotificationBuilder;
use App\Services\SmsManagerService;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The SMS a customer gets after buying at the till. It must reach a real
 * customer once the shop switches it on, and nobody otherwise - above all not
 * the Walking Customer, whose placeholder phone every anonymous sale is booked
 * against.
 */
class PosOrderSmsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function switchAlert(int $state): void
    {
        NotificationAlert::where('language', PosOrderSmsNotificationBuilder::ALERT)->update([
            'sms'         => $state,
            'sms_message' => 'Dear {name}, order #{order}, total Tk {total}.',
        ]);
    }

    private function customer(array $attributes = []): User
    {
        $n = ++$this->sequence;

        return User::create($attributes + [
            'name'         => 'Rahim',
            'username'     => 'rahim' . $n,
            'email'        => 'rahim' . $n . '@example.test',
            'password'     => bcrypt('secret'),
            'status'       => Status::ACTIVE,
            'country_code' => '+880',
            'phone'        => '1712345678',
        ]);
    }

    private function sale(User $customer): Order
    {
        return Order::forceCreate([
            'order_serial_no' => '110926' . $customer->id,
            'user_id'         => $customer->id,
            'subtotal'        => 1250,
            'total'           => 1250,
            'status'          => OrderStatus::CONFIRMED,
        ]);
    }

    /** Stands in for the configured gateway, so no real SMS can leave a test. */
    private function gateway(callable $expectations): void
    {
        $this->mock(SmsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('gateway')->andReturn('bulksmsbd');
        });
        $this->mock(SmsManagerService::class, function (MockInterface $mock) use ($expectations) {
            $mock->shouldReceive('gateway')->with('bulksmsbd')->andReturnSelf();
            $mock->shouldReceive('status')->andReturn(true);
            $expectations($mock);
        });
    }

    public function test_the_message_ships_switched_off(): void
    {
        $alert = NotificationAlert::where('language', PosOrderSmsNotificationBuilder::ALERT)->first();

        $this->assertNotNull($alert, 'the row Settings > Notification Alert lists');
        $this->assertSame((int) SwitchBox::OFF, (int) $alert->sms);
    }

    public function test_a_customer_with_a_name_and_phone_gets_the_message_with_the_sale_filled_in(): void
    {
        $this->switchAlert(SwitchBox::ON);
        $order = $this->sale($this->customer());

        $this->gateway(function (MockInterface $mock) use ($order) {
            $mock->shouldReceive('send')->once()->with(
                '+880',
                '1712345678',
                'Dear Rahim, order #' . $order->order_serial_no . ', total Tk ' . AppLibrary::flatAmountFormat(1250) . '.'
            );
        });

        app(PosOrderSmsNotificationBuilder::class)->send($order->id);
    }

    public function test_nothing_is_sent_while_the_shop_has_it_switched_off(): void
    {
        $this->switchAlert(SwitchBox::OFF);
        $order = $this->sale($this->customer());

        $this->gateway(fn(MockInterface $mock) => $mock->shouldNotReceive('send'));

        app(PosOrderSmsNotificationBuilder::class)->send($order->id);
    }

    public function test_the_walking_customer_is_never_texted_despite_its_placeholder_phone(): void
    {
        $this->switchAlert(SwitchBox::ON);
        $order = $this->sale($this->customer([
            'name'     => 'Walking Customer',
            'username' => 'default-customer',
            'phone'    => '125444455',
        ]));

        $this->gateway(fn(MockInterface $mock) => $mock->shouldNotReceive('send'));

        app(PosOrderSmsNotificationBuilder::class)->send($order->id);
    }

    public function test_a_customer_added_without_a_phone_gets_nothing(): void
    {
        $this->switchAlert(SwitchBox::ON);
        $order = $this->sale($this->customer(['phone' => '']));

        $this->gateway(fn(MockInterface $mock) => $mock->shouldNotReceive('send'));

        app(PosOrderSmsNotificationBuilder::class)->send($order->id);
    }

    /**
     * The gateway can take up to 30s on a bad connection and the queue runs
     * sync, so the SMS must wait until the till already has its answer.
     */
    public function test_the_till_gets_its_response_before_the_sms_is_attempted(): void
    {
        Event::fake([SendPosOrderSms::class, SendPosOrderTelegram::class]);

        $outlet = Outlet::create([
            'name'     => 'Main',
            'email'    => 'main@example.test',
            'phone'    => '01700000000',
            'city'     => 'Dhaka',
            'state'    => 'Dhaka',
            'zip_code' => '1212',
            'address'  => 'Main, Dhaka',
            'status'   => Status::ACTIVE,
        ]);
        $product = Product::create([
            'name'            => 'Soap',
            'slug'            => 'soap',
            'sku'             => 'SOAP1',
            'status'          => Status::ACTIVE,
            'can_purchasable' => Ask::YES,
            'buying_price'    => 100,
            'selling_price'   => 150,
            'variation_price' => 150,
        ]);

        $request = PosOrderRequest::create('/api/admin/pos', 'POST', [
            'customer_id'         => $this->customer()->id,
            'outlet_id'           => $outlet->id,
            'subtotal'            => 150,
            'discount'            => 0,
            'tax'                 => 0,
            'total'               => 150,
            'order_type'          => OrderType::POS,
            'source'              => Source::POS,
            'pos_payment_method'  => PosPaymentMethod::CASH,
            'pos_received_amount' => 200,
            'products'            => json_encode([[
                'product_id'      => $product->id,
                'variation_id'    => 0,
                'variation_names' => null,
                'sku'             => 'SOAP1',
                'price'           => 150,
                'quantity'        => 1,
                'discount'        => 0,
                'total_tax'       => 0,
                'subtotal'        => 150,
                'total'           => 150,
                'taxes'           => [],
            ]]),
        ]);
        $request->setContainer(app())->validateResolved();

        $order = app(OrderService::class)->posOrderStore($request);

        Event::assertNotDispatched(SendPosOrderSms::class);
        // Telegram too: its API is given up to 8s, and the till sat on the
        // spinner for all of it whenever Telegram was slow to answer.
        Event::assertNotDispatched(SendPosOrderTelegram::class);

        $this->app->terminate();

        Event::assertDispatched(SendPosOrderSms::class, fn($event) => $event->info['order_id'] === $order->id);
        Event::assertDispatched(SendPosOrderTelegram::class, fn($event) => $event->info['order_id'] === $order->id);
    }
}
