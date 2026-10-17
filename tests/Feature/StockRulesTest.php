<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Role as EnumRole;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\StockAdjustmentType;
use App\Events\SendLowStockTelegram;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Events\SendOrderSms;
use App\Events\SendPosOrderSms;
use App\Events\SendPosOrderTelegram;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Services\LowStockTelegramNotificationService;
use Dipokhalder\Settings\Facades\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Stock is added by hand and taken out only by sales: the stock page adds to
 * what is there and never takes away, no branch ever holds less than
 * nothing, and the shop chat hears when an item is down to its last one.
 *
 * A refused save in these services rolls back more than its own work under
 * RefreshDatabase (see the failed-request memory), so each refusal is the
 * test's last request and only its answer is checked.
 */
class StockRulesTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $main;
    private Outlet $second;
    private Product $soap;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([SendPosOrderSms::class, SendPosOrderTelegram::class, SendOrderMail::class, SendOrderSms::class,
            SendOrderPush::class, SendLowStockTelegram::class]);

        foreach (['Admin', 'Customer', 'Manager', 'POS Operator', 'Stuff'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'sanctum']);
        }
        foreach (['pos', 'stock', 'damage_create'] as $permission) {
            Permission::findOrCreate($permission, 'sanctum');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $owner = $this->user('Owner', EnumRole::MANAGER);
        $owner->givePermissionTo(['pos', 'stock', 'damage_create']);
        Sanctum::actingAs($owner);

        $this->main   = $this->outlet('Main');
        $this->second = $this->outlet('Second');
        $this->soap   = $this->product('Soap');
    }

    // ------------------------------------------------------------ helpers

    private function user(string $name, ?int $role = null): User
    {
        $n    = ++$this->sequence;
        $user = User::create([
            'name' => $name, 'username' => 'user' . $n, 'email' => 'user' . $n . '@example.test',
            'password' => bcrypt('secret'), 'status' => Status::ACTIVE,
        ]);
        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function outlet(string $name): Outlet
    {
        return Outlet::create([
            'name' => $name, 'email' => strtolower($name) . '@example.test', 'phone' => '01700000000', 'city' => 'Dhaka',
            'state' => 'Dhaka', 'zip_code' => '1212', 'address' => $name . ', Dhaka', 'status' => Status::ACTIVE,
        ]);
    }

    private function product(string $name, int $tracked = Ask::YES): Product
    {
        return Product::create([
            'name' => $name, 'slug' => strtolower($name), 'sku' => strtoupper($name) . '1', 'status' => Status::ACTIVE,
            'can_purchasable' => $tracked, 'buying_price' => 100, 'selling_price' => 150, 'variation_price' => 150,
        ]);
    }

    /** Stock already on record at a branch (null: the unassigned pool). */
    private function holds(Product $product, ?Outlet $outlet, int $quantity): void
    {
        Stock::create([
            'product_id' => $product->id, 'outlet_id' => $outlet?->id, 'model_type' => Product::class, 'model_id' => $product->id,
            'item_type' => Product::class, 'item_id' => $product->id, 'quantity' => $quantity, 'status' => Status::ACTIVE,
        ]);
    }

    private function onShelf(Product $product, ?Outlet $outlet): int
    {
        return (int) Stock::where('item_type', Product::class)->where('item_id', $product->id)->where('status', Status::ACTIVE)
            ->where('outlet_id', $outlet?->id)->sum('quantity');
    }

    private function sell(Product $product, int $quantity)
    {
        return $this->postJson('/api/admin/pos', [
            'customer_id' => $this->user('Walk In', EnumRole::CUSTOMER)->id, 'outlet_id' => $this->main->id,
            'subtotal' => 150 * $quantity, 'discount' => 0, 'tax' => 0, 'total' => 150 * $quantity,
            'order_type' => OrderType::POS, 'source' => Source::POS, 'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_payment_note' => '', 'pos_received_amount' => 150 * $quantity,
            'products' => json_encode([[
                'product_id' => $product->id, 'variation_id' => 0, 'variation_names' => null, 'sku' => $product->sku,
                'price' => 150, 'quantity' => $quantity, 'discount' => 0, 'total_tax' => 0,
                'subtotal' => 150 * $quantity, 'total' => 150 * $quantity, 'taxes' => [],
            ]]),
        ]);
    }

    private function editStock(array $stocks)
    {
        return $this->postJson('/api/admin/stock/update-item', [
            'product_id' => $this->soap->id, 'variation_id' => 0, 'stocks' => json_encode($stocks),
        ]);
    }

    private function adjust(int $type, ?Outlet $from, ?Outlet $to, int $quantity)
    {
        return $this->postJson('/api/admin/stock/adjustment', [
            'type' => $type, 'from_outlet_id' => $from?->id, 'to_outlet_id' => $to?->id, 'date' => '2026-10-16 10:00',
            'products' => json_encode([[
                'product_id' => $this->soap->id, 'variation_id' => 0, 'sku' => 'SOAP1', 'variation_names' => null, 'quantity' => $quantity,
            ]]),
        ]);
    }

    // --------------------------------------------------- the stock page

    public function test_the_stock_edit_adds_to_what_is_there(): void
    {
        $this->holds($this->soap, $this->main, 5);

        $this->editStock([['outlet_id' => $this->main->id, 'add' => 3], ['outlet_id' => $this->second->id, 'add' => 0]])->assertOk();

        $this->assertSame(8, $this->onShelf($this->soap, $this->main));
        $this->assertSame(0, $this->onShelf($this->soap, $this->second));
        $this->assertSame([StockAdjustmentType::ADD], StockAdjustment::pluck('type')->map(fn($type) => (int) $type)->all(), 'one record, for the branch that got stock');
    }

    public function test_the_stock_edit_never_takes_stock_away(): void
    {
        $this->editStock([['outlet_id' => $this->main->id, 'add' => -2]])->assertStatus(422);
    }

    // An old screen still open after the update sends the count to set, not
    // the units to add: refused rather than added by mistake.
    public function test_the_old_set_the_count_payload_is_refused(): void
    {
        $this->editStock([['outlet_id' => $this->main->id, 'quantity' => 20]])->assertStatus(422);
    }

    public function test_a_remove_adjustment_is_refused(): void
    {
        $this->holds($this->soap, $this->main, 5);

        $this->adjust(StockAdjustmentType::REMOVE, $this->main, null, 2)->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_a_recalculate_adjustment_is_refused(): void
    {
        $this->holds($this->soap, $this->main, 5);

        $this->adjust(StockAdjustmentType::RECALCULATE, null, $this->main, 1)->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_stock_moves_between_branches_within_what_the_branch_holds(): void
    {
        $this->holds($this->soap, $this->main, 2);

        $this->adjust(StockAdjustmentType::TRANSFER, $this->main, $this->second, 2)->assertSuccessful();

        $this->assertSame([0, 2], [$this->onShelf($this->soap, $this->main), $this->onShelf($this->soap, $this->second)]);
    }

    public function test_a_transfer_cannot_move_more_than_the_branch_holds(): void
    {
        $this->holds($this->soap, $this->main, 2);

        $this->adjust(StockAdjustmentType::TRANSFER, $this->main, $this->second, 3)->assertStatus(422)
            ->assertJsonPath('message', fn(string $message) => str_contains($message, 'only 2 left'));
    }

    public function test_an_added_stock_cannot_be_deleted_away(): void
    {
        $this->adjust(StockAdjustmentType::ADD, null, $this->main, 5)->assertSuccessful();

        $this->deleteJson('/api/admin/stock/adjustment/' . StockAdjustment::sole()->id)->assertStatus(422);
    }

    // ------------------------------------------------------------ sales

    public function test_a_pos_sale_cannot_sell_more_than_the_branch_holds(): void
    {
        $this->holds($this->soap, $this->main, 1);
        $this->holds($this->soap, $this->second, 9);

        $this->sell($this->soap, 2)->assertStatus(422)
            ->assertJsonPath('message', fn(string $message) => str_contains($message, 'Soap') && str_contains($message, 'Main') && str_contains($message, 'only 1 left'));
    }

    public function test_a_sale_that_leaves_one_alerts_the_shop_chat(): void
    {
        $this->holds($this->soap, $this->main, 3);

        $this->sell($this->soap, 2)->assertSuccessful();

        $this->assertSame(1, $this->onShelf($this->soap, $this->main));
        Event::assertDispatched(SendLowStockTelegram::class, fn(SendLowStockTelegram $event) => $event->info['item_id'] === $this->soap->id
            && $event->info['outlet_id'] === $this->main->id && $event->info['left'] === 1);
    }

    public function test_a_sale_that_empties_the_branch_alerts_too(): void
    {
        $this->holds($this->soap, $this->main, 2);

        $this->sell($this->soap, 2)->assertSuccessful();

        Event::assertDispatched(SendLowStockTelegram::class, fn(SendLowStockTelegram $event) => $event->info['left'] === 0);
    }

    public function test_a_sale_that_leaves_more_than_one_sends_nothing(): void
    {
        $this->holds($this->soap, $this->main, 5);

        $this->sell($this->soap, 2)->assertSuccessful();

        Event::assertNotDispatched(SendLowStockTelegram::class);
    }

    // A product the shop does not count (can_purchasable = NO) sells freely.
    public function test_an_untracked_product_sells_without_stock(): void
    {
        $card = $this->product('Gift', Ask::NO);

        $this->sell($card, 1)->assertSuccessful();

        Event::assertNotDispatched(SendLowStockTelegram::class);
    }

    // ----------------------------------------------------------- damage

    private function damage(int $quantity)
    {
        return $this->postJson('/api/admin/damage', [
            'date' => '2026-10-16 10:00', 'subtotal' => 150 * $quantity, 'discount' => 0, 'tax' => 0, 'total' => 150 * $quantity,
            'products' => json_encode([[
                'product_id' => $this->soap->id, 'is_variation' => false, 'item_id' => $this->soap->id, 'variation_names' => null,
                'sku' => 'SOAP1', 'price' => 150, 'quantity' => $quantity, 'total_discount' => 0, 'total_tax' => 0,
                'subtotal' => 150 * $quantity, 'total' => 150 * $quantity, 'tax_id' => [],
            ]]),
        ]);
    }

    public function test_a_damage_within_the_shops_stock_is_recorded(): void
    {
        $this->holds($this->soap, $this->main, 5);

        $this->damage(2)->assertSuccessful();

        $this->assertSame(3, (int) Stock::where('item_id', $this->soap->id)->where('status', Status::ACTIVE)->sum('quantity'));
    }

    public function test_a_damage_cannot_take_more_than_the_shop_holds(): void
    {
        $this->holds($this->soap, $this->main, 1);

        $this->damage(2)->assertStatus(422);
    }

    // ------------------------------------------------------------ alert

    public function test_the_alert_names_the_product_the_branch_and_what_is_left(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        Settings::group('telegram')->set(['telegram_status' => Ask::YES, 'telegram_bot_token' => 'TEST-TOKEN', 'telegram_chat_id' => '-100123']);
        $this->holds($this->soap, $this->main, 1);
        $this->holds($this->soap, $this->second, 4);

        app(LowStockTelegramNotificationService::class)->send([
            'product_id' => $this->soap->id, 'item_type' => Product::class, 'item_id' => $this->soap->id, 'outlet_id' => $this->main->id, 'left' => 1,
        ]);

        $sent = Http::recorded()->map(fn($pair) => $pair[0]->data())->values()->all();
        $this->assertCount(1, $sent);
        $text = $sent[0]['text'];
        $this->assertStringContainsString('<b>Low stock</b>', $text);
        $this->assertStringContainsString('Product: Soap', $text);
        $this->assertStringContainsString('SKU: SOAP1', $text);
        $this->assertStringContainsString('Branch: Main - 1 left', $text);
        $this->assertStringContainsString('All branches: 5', $text);
    }
}
