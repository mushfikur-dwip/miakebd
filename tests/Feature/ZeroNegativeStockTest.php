<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\StockAdjustmentType;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Stock;
use App\Models\StockAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Before sales were checked against stock, the till could sell more than a
 * branch held and leave it below zero. stock:zero-negatives lists every item
 * below zero and, with --fix, adds exactly what brings it back to 0 - as an
 * Add adjustment, so the history shows it.
 */
class ZeroNegativeStockTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $main;
    private Outlet $second;
    private Product $soap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->main   = $this->outlet('Main');
        $this->second = $this->outlet('Second');
        $this->soap   = Product::forceCreate([
            'name' => 'Soap', 'slug' => 'soap', 'sku' => 'SOAP1', 'status' => Status::ACTIVE,
            'buying_price' => 100, 'selling_price' => 150, 'variation_price' => 150,
        ]);
    }

    private function outlet(string $name): Outlet
    {
        return Outlet::create([
            'name' => $name, 'email' => strtolower($name) . '@example.test', 'phone' => '01700000000', 'city' => 'Dhaka',
            'state' => 'Dhaka', 'zip_code' => '1212', 'address' => $name . ', Dhaka', 'status' => Status::ACTIVE,
        ]);
    }

    private function row(?Outlet $outlet, int $quantity, string $itemType = Product::class, ?int $itemId = null, int $status = Status::ACTIVE): void
    {
        Stock::forceCreate([
            'product_id' => $this->soap->id, 'outlet_id' => $outlet?->id, 'model_type' => Product::class, 'model_id' => $this->soap->id,
            'item_type' => $itemType, 'item_id' => $itemId ?? $this->soap->id, 'quantity' => $quantity, 'status' => $status,
        ]);
    }

    private function held(?Outlet $outlet, string $itemType = Product::class, ?int $itemId = null): int
    {
        return (int) Stock::where('item_type', $itemType)->where('item_id', $itemId ?? $this->soap->id)
            ->where('outlet_id', $outlet?->id)->where('status', Status::ACTIVE)->sum('quantity');
    }

    public function test_the_report_lists_negative_stock_and_changes_nothing(): void
    {
        $this->row($this->main, -3);
        $this->row($this->second, 5);

        $this->assertSame(0, Artisan::call('stock:zero-negatives'));
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/Soap.*SOAP1.*Main.*-3.*3/', $output);
        $this->assertStringContainsString('1 item below zero at 1 place', $output);
        $this->assertStringNotContainsString('Second', $output);

        $this->assertSame([-3, 5], [$this->held($this->main), $this->held($this->second)]);
        $this->assertSame(0, StockAdjustment::count());
    }

    public function test_fix_brings_every_branch_back_to_zero_and_leaves_the_rest_alone(): void
    {
        $this->row($this->main, 2);
        $this->row($this->main, -7);
        $this->row($this->second, 5);

        $this->artisan('stock:zero-negatives', ['--fix' => true])
            ->expectsOutputToContain('Added 5 units')
            ->assertSuccessful();

        $this->assertSame([0, 5], [$this->held($this->main), $this->held($this->second)]);
        $adjustment = StockAdjustment::sole();
        $this->assertSame(StockAdjustmentType::ADD, (int) $adjustment->type);
        $this->assertSame($this->main->id, $adjustment->to_outlet_id);
    }

    public function test_a_variation_is_an_item_of_its_own(): void
    {
        // A variation's stock rows name it by item_type/item_id; the row itself is all the command reads.
        $variation = (object) ['id' => 900];
        $this->row($this->main, -2, ProductVariation::class, $variation->id);
        $this->row($this->main, 4);

        $this->artisan('stock:zero-negatives', ['--fix' => true])->assertSuccessful();

        $this->assertSame(0, $this->held($this->main, ProductVariation::class, $variation->id));
        $this->assertSame(4, $this->held($this->main), 'the plain product untouched');
    }

    // Damage is written to the unassigned pool, so the pool below zero is
    // fine while the branches cover it: only an item whose shop-wide total is
    // below zero gets the pool raised, and only as far as that total needs.
    public function test_the_unassigned_pool_is_raised_only_as_far_as_the_shop_total_needs(): void
    {
        $this->row(null, -2);
        $this->row($this->main, 10);

        $this->artisan('stock:zero-negatives', ['--fix' => true])->assertSuccessful();
        $this->assertSame(-2, $this->held(null), 'a write-off the branches cover stays');

        Stock::query()->delete();
        $this->row(null, -5);
        $this->row($this->main, 2);

        $this->artisan('stock:zero-negatives', ['--fix' => true])->assertSuccessful();
        $this->assertSame(-2, $this->held(null));
        $this->assertSame(0, $this->held(null) + $this->held($this->main), 'the shop total is back at 0');
    }

    public function test_rows_not_yet_counted_are_ignored(): void
    {
        $this->row($this->main, -4, Product::class, null, Status::INACTIVE);

        $this->artisan('stock:zero-negatives', ['--fix' => true])
            ->expectsOutputToContain('No stock is below zero')
            ->assertSuccessful();

        $this->assertSame(0, StockAdjustment::count());
    }

    // Releasing a cancelled order's stock afterwards would push the item
    // above what is really there, so that comes first.
    public function test_fix_waits_while_cancelled_orders_still_hold_stock(): void
    {
        $this->row($this->main, -3);
        $customer = User::create(['name' => 'Karim', 'username' => 'karim', 'email' => 'k@example.test', 'password' => bcrypt('x'), 'status' => Status::ACTIVE]);
        $order    = Order::forceCreate([
            'user_id' => $customer->id, 'subtotal' => 150, 'total' => 150, 'status' => OrderStatus::CANCELED,
            'payment_status' => PaymentStatus::PAID, 'order_type' => OrderType::POS, 'source' => Source::POS, 'order_datetime' => now(),
        ]);
        Stock::forceCreate([
            'product_id' => $this->soap->id, 'outlet_id' => $this->main->id, 'model_type' => Order::class, 'model_id' => $order->id,
            'item_type' => Product::class, 'item_id' => $this->soap->id, 'quantity' => -1, 'status' => Status::ACTIVE,
        ]);

        $this->artisan('stock:zero-negatives', ['--fix' => true])
            ->expectsOutputToContain('orders:cancelled-stock')
            ->assertFailed();

        $this->assertSame(-4, $this->held($this->main), 'nothing changed');
    }
}
