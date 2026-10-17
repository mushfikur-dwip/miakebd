<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Role as EnumRole;
use App\Enums\Source;
use App\Enums\Status;
use App\Events\SendLowStockTelegram;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Events\SendOrderSms;
use App\Events\SendPosOrderSms;
use App\Events\SendPosOrderTelegram;
use App\Models\CashPinFailure;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Services\CashPinService;
use App\Support\StockPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * One switch on the Stock page, behind the branch PIN.
 *
 * On (the default): nothing is sold past what the shop holds - not at the till,
 * not on the website. Off: the sale goes through, the stock row really does go
 * negative, and the stock page shows 0 with a note of what was billed beyond
 * stock. Stock that covers the sale is never affected either way.
 *
 * A refused save rolls back more than its own work under RefreshDatabase (see
 * the failed-request memory), so each refusal is its test's last request.
 */
class StockZeroSwitchTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $main;
    private Product $soap;
    private User $owner;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([SendPosOrderSms::class, SendPosOrderTelegram::class, SendOrderMail::class,
            SendOrderSms::class, SendOrderPush::class, SendLowStockTelegram::class]);

        foreach (['Admin', 'Customer', 'Manager', 'POS Operator', 'Stuff'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'sanctum']);
        }
        foreach (['pos', 'stock'] as $permission) {
            Permission::findOrCreate($permission, 'sanctum');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->owner = $this->user('Owner', EnumRole::MANAGER);
        $this->owner->givePermissionTo(['pos', 'stock']);
        Sanctum::actingAs($this->owner);

        $this->main = $this->outlet('Main');
        $this->soap = $this->product('Soap');
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

    private function holds(Product $product, ?Outlet $outlet, int $quantity): void
    {
        Stock::create([
            'product_id' => $product->id, 'outlet_id' => $outlet?->id, 'model_type' => Product::class,
            'model_id' => $product->id, 'item_type' => Product::class, 'item_id' => $product->id,
            'quantity' => $quantity, 'status' => Status::ACTIVE,
        ]);
    }

    private function onShelf(Product $product, ?Outlet $outlet): int
    {
        return (int) Stock::where('item_type', Product::class)->where('item_id', $product->id)
            ->where('status', Status::ACTIVE)->where('outlet_id', $outlet?->id)->sum('quantity');
    }

    /** Turns the switch off (or on) the way the page does, with the PIN. */
    private function setSwitch(bool $on, string $pin = CashPinService::DEFAULT_PIN)
    {
        return $this->putJson('/api/admin/stock/setup', [
            'outlet_id' => $this->main->id, 'stock_cannot_be_zero' => $on, 'pin' => $pin,
        ]);
    }

    /** Off, without going through the endpoint - for the selling tests. */
    private function switchOff(): void
    {
        $this->setSwitch(false)->assertOk();
        app(StockPolicy::class)->forget();
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

    private function stockRow(): array
    {
        $rows = $this->getJson('/api/admin/stock?paginate=0')->assertOk()->json('data');

        return collect($rows)->firstWhere('product_id', $this->soap->id);
    }

    // ------------------------------------------------------- the switch

    public function test_the_switch_starts_on(): void
    {
        $this->getJson('/api/admin/stock/setup')->assertOk()
            ->assertJsonPath('data.stock_cannot_be_zero', true);
    }

    public function test_the_switch_needs_the_branch_pin(): void
    {
        $this->setSwitch(false, '00000')->assertStatus(422);

        $this->assertTrue(app(StockPolicy::class)->cannotBeZero(), 'still on');
        $this->assertSame(1, CashPinFailure::where('action', 'stock_zero_switch')->count());
    }

    public function test_the_switch_turns_off_and_on_again_with_the_pin(): void
    {
        $this->setSwitch(false)->assertOk()->assertJsonPath('data.stock_cannot_be_zero', false);
        app(StockPolicy::class)->forget();
        $this->assertFalse(app(StockPolicy::class)->cannotBeZero());

        $this->setSwitch(true)->assertOk()->assertJsonPath('data.stock_cannot_be_zero', true);
        app(StockPolicy::class)->forget();
        $this->assertTrue(app(StockPolicy::class)->cannotBeZero());
    }

    // ------------------------------------------------- selling at the till

    public function test_with_the_switch_on_the_till_cannot_sell_past_stock(): void
    {
        $this->holds($this->soap, $this->main, 2);

        $this->sell($this->soap, 3)->assertStatus(422);
    }

    public function test_with_the_switch_off_the_till_sells_past_stock_and_the_row_goes_negative(): void
    {
        $this->holds($this->soap, $this->main, 2);
        $this->switchOff();

        $this->sell($this->soap, 3)->assertSuccessful();

        // The ledger stays truthful: the next delivery has to absorb this.
        $this->assertSame(-1, $this->onShelf($this->soap, $this->main));
    }

    /**
     * The whole point of the switch is the shortfall. A sale the shelf covers
     * behaves identically in both positions.
     */
    public function test_a_sale_the_stock_covers_is_untouched_either_way(): void
    {
        $this->holds($this->soap, $this->main, 10);

        $this->sell($this->soap, 4)->assertSuccessful();
        $this->assertSame(6, $this->onShelf($this->soap, $this->main));

        $this->switchOff();
        $this->sell($this->soap, 4)->assertSuccessful();
        $this->assertSame(2, $this->onShelf($this->soap, $this->main));
    }

    public function test_an_untracked_product_sells_with_the_switch_on(): void
    {
        $tea = $this->product('Tea', Ask::NO);

        $this->sell($tea, 5)->assertSuccessful();
    }

    // ------------------------------------------------- the stock page

    public function test_an_oversold_branch_shows_zero_and_the_remark(): void
    {
        $this->holds($this->soap, $this->main, 2);
        $this->switchOff();
        $this->sell($this->soap, 3)->assertSuccessful();

        $row = $this->stockRow();

        $this->assertSame(0, $row['stock'], 'a negative count tells a shopkeeper nothing');
        $this->assertSame(-1, $row['real_stock'], 'the truth still travels, for the edit dialog');
        $this->assertSame(1, $row['billed_beyond']);

        $branch = collect($row['outlet_stocks'])->firstWhere('outlet_id', $this->main->id);
        $this->assertSame(0, $branch['quantity']);
        $this->assertSame(-1, $branch['real_quantity']);
        $this->assertSame(1, $branch['billed_beyond']);
    }

    /**
     * With the switch ON a negative is damage from before the rule existed,
     * and the owner has to see it to clear it with stock:zero-negatives.
     */
    public function test_with_the_switch_on_an_old_negative_is_shown_as_it_is(): void
    {
        $this->holds($this->soap, $this->main, -1);

        $row = $this->stockRow();

        $this->assertSame(-1, $row['stock']);
        $this->assertSame(0, $row['billed_beyond'], 'nothing is being sold beyond stock while it is on');
    }

    public function test_stock_that_covers_everything_carries_no_remark(): void
    {
        $this->holds($this->soap, $this->main, 7);
        $this->switchOff();

        $row = $this->stockRow();

        $this->assertSame(7, $row['stock']);
        $this->assertSame(7, $row['real_stock']);
        $this->assertSame(0, $row['billed_beyond']);
    }

    // --------------------------------------------- selling on the website

    /**
     * The till was guarded before this change and the website was not: an
     * online order wrote its stock rows inactive and the payment step turned
     * them on without ever checking, so a customer could order any quantity of
     * anything. This is the missing half.
     */
    private function orderOnline(int $quantity, ?Outlet $outlet = null)
    {
        $customer = $this->user('Rima', EnumRole::CUSTOMER);
        Sanctum::actingAs($customer);

        $address = \App\Models\Address::create([
            'user_id' => $customer->id, 'full_name' => 'Rima', 'phone' => '01711111111', 'country_code' => '+880',
            'country' => 'Bangladesh', 'state' => 'Dhaka', 'address' => 'House 1',
        ]);

        return $this->postJson('/api/frontend/order', [
            'subtotal' => 150 * $quantity, 'discount' => 0, 'tax' => 0, 'shipping_charge' => 0,
            'total' => 150 * $quantity,
            'order_type' => $outlet ? OrderType::PICK_UP : OrderType::DELIVERY,
            'shipping_id' => $address->id, 'billing_id' => $address->id,
            'outlet_id' => $outlet?->id, 'coupon_id' => 0, 'source' => 5, 'payment_method' => 1,
            'wallet_discount' => 0,
            'products' => json_encode([[
                'product_id' => $this->soap->id, 'variation_id' => 0, 'variation_names' => '', 'sku' => 'SOAP1',
                'price' => 150, 'quantity' => $quantity, 'discount' => 0, 'total_tax' => 0,
                'subtotal' => 150 * $quantity, 'total' => 150 * $quantity, 'taxes' => [],
                'price_source' => 'catalogue',
            ]]),
        ]);
    }

    public function test_with_the_switch_on_the_website_cannot_sell_past_stock(): void
    {
        $this->soap->update(['maximum_purchase_quantity' => 100]);
        $this->holds($this->soap, null, 2);

        $this->orderOnline(3)->assertStatus(422);
    }

    public function test_with_the_switch_off_the_website_sells_past_stock(): void
    {
        $this->soap->update(['maximum_purchase_quantity' => 100]);
        $this->holds($this->soap, null, 2);
        $this->switchOff();

        $this->orderOnline(3)->assertCreated();
    }

    public function test_the_website_sells_what_the_stock_covers(): void
    {
        $this->soap->update(['maximum_purchase_quantity' => 100]);
        $this->holds($this->soap, null, 10);

        $this->orderOnline(4)->assertCreated();
    }

    /**
     * A delivery order has no branch and draws on the shop's whole stock.
     * Comparing it against one branch's shelf would refuse every delivery.
     */
    public function test_a_delivery_order_draws_on_the_whole_shops_stock(): void
    {
        $this->soap->update(['maximum_purchase_quantity' => 100]);
        $this->holds($this->soap, $this->main, 6);

        $this->orderOnline(5)->assertCreated();
    }

    /**
     * Products the shop does not count (can_purchasable = NO) are most of the
     * catalogue and exactly the ones sold with nothing on the shelf. The build
     * that left them out showed -1 on the stock page with the switch off, which
     * is what the owner reported - so they get 0 and the remark like the rest.
     */
    public function test_an_untracked_product_also_shows_zero_and_the_remark(): void
    {
        $tea = $this->product('Tea', Ask::NO);
        $this->holds($tea, $this->main, -4);
        $this->switchOff();

        $rows = $this->getJson('/api/admin/stock?paginate=0')->assertOk()->json('data');
        $row  = collect($rows)->firstWhere('product_id', $tea->id);

        $this->assertSame(0, $row['stock'], 'never below zero while the switch is off');
        $this->assertSame(-4, $row['real_stock']);
        $this->assertSame(4, $row['billed_beyond']);
        $this->assertFalse($row['stock_tracked'], 'still flagged as not stock controlled');

        $branch = collect($row['outlet_stocks'])->firstWhere('outlet_id', $this->main->id);
        $this->assertSame(0, $branch['quantity']);
        $this->assertSame(4, $branch['billed_beyond']);
    }

    /**
     * The till never refuses an untracked product, so it can go below zero
     * with the switch on as well. The owner's rule (2026-10-17): the page
     * still never shows a negative for it - 0 and the remark, either way.
     */
    public function test_with_the_switch_on_an_untracked_product_still_shows_zero_and_the_remark(): void
    {
        $tea = $this->product('Tea', Ask::NO);
        $this->sell($tea, 2)->assertSuccessful();

        $rows = $this->getJson('/api/admin/stock?paginate=0')->assertOk()->json('data');
        $row  = collect($rows)->firstWhere('product_id', $tea->id);

        $this->assertSame(0, $row['stock']);
        $this->assertSame(-2, $row['real_stock']);
        $this->assertSame(2, $row['billed_beyond']);

        $branch = collect($row['outlet_stocks'])->firstWhere('outlet_id', $this->main->id);
        $this->assertSame(0, $branch['quantity']);
        $this->assertSame(-2, $branch['real_quantity']);
        $this->assertSame(2, $branch['billed_beyond']);
    }

    /** The Excel export is read away from the page, so it carries the remark too. */
    public function test_the_export_shows_zero_and_what_was_billed_beyond_stock(): void
    {
        $this->holds($this->soap, $this->main, 2);
        $this->switchOff();
        $this->sell($this->soap, 3)->assertSuccessful();

        $request = new \App\Http\Requests\PaginateRequest();
        $rows    = (new \App\Exports\StockExport(app(\App\Services\StockService::class), $request))->collection();
        $row     = $rows->first(fn($row) => $row[0] === 'Soap');

        $this->assertSame(0, $row[2], 'never below zero while the switch is off');
        $this->assertSame(1, $row[3], 'units billed beyond stock');
        $this->assertStringContainsString('Main: 0 (-1)', $row[4]);
    }
}
