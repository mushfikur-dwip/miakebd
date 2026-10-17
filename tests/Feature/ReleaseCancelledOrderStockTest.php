<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Order;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Before cancelling gave stock back, an order cancelled or rejected from the
 * admin kept its units off the shelf for good. The command lists those
 * orders, and with --fix gives their stock back - nothing else.
 */
class ReleaseCancelledOrderStockTest extends TestCase
{
    use RefreshDatabase;

    private Product $soap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->soap = Product::forceCreate([
            'name' => 'Soap', 'slug' => 'soap', 'sku' => 'SOAP1', 'status' => Status::ACTIVE,
            'buying_price' => 100, 'selling_price' => 150, 'variation_price' => 150,
        ]);
    }

    /** An order of $units Soap, written the way a sale records it; its status set without events, as before the fix. */
    private function order(int $status, int $units): Order
    {
        $customer = User::create([
            'name' => 'Karim', 'username' => 'karim' . $status . $units, 'email' => 'karim' . $status . $units . '@example.test',
            'password' => bcrypt('x'), 'status' => Status::ACTIVE,
        ]);
        $order = Order::forceCreate([
            'user_id' => $customer->id, 'subtotal' => 150 * $units, 'total' => 150 * $units, 'status' => OrderStatus::CONFIRMED,
            'payment_status' => PaymentStatus::PAID, 'order_type' => OrderType::DELIVERY, 'source' => Source::WEB,
            'order_datetime' => '2026-09-10 10:00:00', 'order_serial_no' => '100926' . $units,
        ]);
        Stock::forceCreate([
            'product_id' => $this->soap->id, 'model_type' => Order::class, 'model_id' => $order->id,
            'item_type' => Product::class, 'item_id' => $this->soap->id, 'quantity' => -$units, 'status' => Status::ACTIVE,
        ]);
        Order::withoutEvents(fn() => $order->update(['status' => $status]));

        return $order;
    }

    private function soapOnShelf(): int
    {
        return (int) Stock::where('product_id', $this->soap->id)->where('status', Status::ACTIVE)->sum('quantity');
    }

    public function test_the_report_lists_cancelled_orders_still_holding_stock_and_changes_nothing(): void
    {
        $this->order(OrderStatus::CANCELED, 2);
        $this->order(OrderStatus::REJECTED, 4);
        $this->order(OrderStatus::CONFIRMED, 3);

        $this->artisan('orders:cancelled-stock')
            ->expectsOutputToContain('1009262')
            ->expectsOutputToContain('1009264')
            ->expectsOutputToContain('2 cancelled or rejected orders still hold 6 units')
            ->doesntExpectOutputToContain('1009263')
            ->assertSuccessful();

        $this->assertSame(-9, $this->soapOnShelf(), 'a report changes nothing');
    }

    public function test_fix_gives_back_only_the_cancelled_orders_stock(): void
    {
        $this->order(OrderStatus::CANCELED, 2);
        $this->order(OrderStatus::REJECTED, 4);
        $this->order(OrderStatus::CONFIRMED, 3);

        $this->artisan('orders:cancelled-stock', ['--fix' => true])
            ->expectsOutputToContain('Released 6 units from 2 orders')
            ->assertSuccessful();

        $this->assertSame(-3, $this->soapOnShelf(), 'only the live order still holds stock');
    }
}
