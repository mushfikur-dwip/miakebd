<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\CashAccount;
use App\Enums\CashEntryType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Role as EnumRole;
use App\Enums\Source;
use App\Enums\Status;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Events\SendOrderSms;
use App\Events\SendPosOrderSms;
use App\Events\SendPosOrderTelegram;
use App\Events\SendPosSaleChangeTelegram;
use App\Libraries\AppLibrary;
use App\Models\CashEntry;
use App\Models\CashPinFailure;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\PosSaleChange;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Services\PosSaleChangeTelegramNotificationService;
use Dipokhalder\Settings\Facades\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Feature\Concerns\StocksShelves;

/**
 * A wrong till sale is put right in one of two ways, each behind a PIN:
 * Reverse takes the whole sale back everywhere (money, stock, every report)
 * and keeps it on record; Change payment method moves its money to where it
 * really went. Every older way of making a sale disappear - delete, cancel,
 * mark unpaid - is refused, so no figure can be quietly bent.
 */
class PosSaleChangeTest extends TestCase
{
    use RefreshDatabase;
    use StocksShelves;

    private Outlet $outlet;
    private Product $product;
    private User $owner;
    private User $cashier;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([SendPosOrderSms::class, SendPosOrderTelegram::class, SendOrderMail::class, SendOrderSms::class,
            SendOrderPush::class, SendPosSaleChangeTelegram::class]);

        // assignRole() looks roles up by id, and App\Enums\Role fixes the ids.
        foreach (['Admin', 'Customer', 'Manager', 'POS Operator', 'Stuff'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'sanctum']);
        }
        foreach (['pos', 'pos-orders', 'online-orders', 'cash-calculation', 'cash-calculation_balance'] as $permission) {
            Permission::findOrCreate($permission, 'sanctum');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->owner = $this->user('Owner', EnumRole::MANAGER);
        $this->owner->givePermissionTo(['pos', 'pos-orders', 'online-orders', 'cash-calculation', 'cash-calculation_balance']);

        $this->cashier = $this->user('Cashier', EnumRole::POS_OPERATOR);
        $this->cashier->givePermissionTo(['pos', 'cash-calculation']);

        $this->outlet  = Outlet::create([
            'name' => 'Main', 'email' => 'main@example.test', 'phone' => '01700000000', 'city' => 'Dhaka',
            'state' => 'Dhaka', 'zip_code' => '1212', 'address' => 'Main, Dhaka', 'status' => Status::ACTIVE,
        ]);
        $this->product = Product::create([
            'name' => 'Soap', 'slug' => 'soap', 'sku' => 'SOAP1', 'status' => Status::ACTIVE, 'can_purchasable' => Ask::YES,
            'buying_price' => 100, 'selling_price' => 150, 'variation_price' => 150,
        ]);
        $this->stockUp($this->product, $this->outlet);

        Sanctum::actingAs($this->owner);
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

    private function ringUp(int $method = PosPaymentMethod::CASH, float $total = 150): Order
    {
        $response = $this->postJson('/api/admin/pos', [
            'customer_id'         => $this->user('Walk In', EnumRole::CUSTOMER)->id,
            'outlet_id'           => $this->outlet->id,
            'subtotal'            => $total,
            'discount'            => 0,
            'tax'                 => 0,
            'total'               => $total,
            'order_type'          => OrderType::POS,
            'source'              => Source::POS,
            'pos_payment_method'  => $method,
            'pos_payment_note'    => $method === PosPaymentMethod::CARD ? '1234' : ($method === PosPaymentMethod::CASH ? '' : 'TX123'),
            'pos_received_amount' => $method === PosPaymentMethod::CASH ? $total : null,
            'products'            => json_encode([[
                'product_id' => $this->product->id, 'variation_id' => 0, 'variation_names' => null, 'sku' => 'SOAP1',
                'price' => $total, 'quantity' => 1, 'discount' => 0, 'total_tax' => 0, 'subtotal' => $total,
                'total' => $total, 'taxes' => [],
            ]]),
        ])->assertSuccessful();

        return Order::findOrFail($response->json('data.id'));
    }

    private function balanceOf(int $account): float
    {
        return round((float) CashEntry::where('outlet_id', $this->outlet->id)->where('account', $account)->sum('amount'), 2);
    }

    /** What the stock screen shows for Soap at the branch. */
    private function soapInStock(): int
    {
        return (int) Stock::where('product_id', $this->product->id)->where('outlet_id', $this->outlet->id)
            ->where('status', Status::ACTIVE)->sum('quantity');
    }

    private function reverse(Order $order, ?string $pin = '51920', ?string $reason = 'Rung up twice by mistake')
    {
        return $this->postJson('/api/admin/pos-order/reverse/' . $order->id, array_filter(['reason' => $reason, 'pin' => $pin]));
    }

    private function changeMethod(Order $order, int $method, ?string $note, ?string $pin = '112233', string $reason = 'Customer paid by card')
    {
        return $this->postJson('/api/admin/pos-order/change-method/' . $order->id, [
            'pos_payment_method' => $method,
            'pos_payment_note'   => $note,
            'reason'             => $reason,
            'pin'                => $pin,
        ]);
    }

    private function summary(): array
    {
        return $this->getJson('/api/admin/cash-calculation/summary?outlet_id=' . $this->outlet->id)->json('data');
    }

    // ------------------------------------------------------------ reverse

    public function test_reversing_a_cash_sale_takes_it_back_everywhere(): void
    {
        $order = $this->ringUp();
        $this->assertSame(999, $this->soapInStock());

        $this->reverse($order)->assertSuccessful()->assertJsonPath('data.is_reversed', true);

        $order->refresh();
        $this->assertSame(OrderStatus::CANCELED, $order->status);
        $this->assertSame('Rung up twice by mistake', $order->reason);
        $this->assertEquals(0, $this->balanceOf(CashAccount::DRAWER), 'the notes are taken back out of the drawer');
        $this->assertSame(1000, $this->soapInStock(), 'the soap is back on the shelf');

        $change = PosSaleChange::sole();
        $this->assertSame([PosSaleChange::REVERSE, 150.0, PosPaymentMethod::CASH, $this->owner->id, $order->id, $this->outlet->id],
            [$change->action, $change->amount, $change->from_method, $change->created_by, $change->order_id, $change->outlet_id]);

        $sales = $this->summary()['sales'];
        $this->assertEquals(0, $sales['day']['total'], 'no longer a sale');
        $this->assertSame(1, $sales['reversed']['count']);
        $this->assertEquals(150, $sales['reversed']['total']);
    }

    public function test_a_reversal_needs_the_branch_pin(): void
    {
        $order = $this->ringUp();

        $this->reverse($order, '112233')->assertStatus(422);

        $this->assertSame(OrderStatus::CONFIRMED, $order->refresh()->status);
        $this->assertEquals(150, $this->balanceOf(CashAccount::DRAWER));
        $this->assertSame(999, $this->soapInStock());
        $this->assertSame(0, PosSaleChange::count());
        $this->assertSame('pos_reverse', CashPinFailure::sole()->action);
    }

    public function test_a_reversal_needs_a_reason(): void
    {
        $order = $this->ringUp();

        $this->reverse($order, '51920', null)->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->assertSame(OrderStatus::CONFIRMED, $order->refresh()->status);
    }

    // Final: a reversed sale cannot come back, change method or change status.
    // A real sale that was reversed by mistake is simply rung up again.
    public function test_a_reversed_sale_is_final(): void
    {
        $order = $this->ringUp();
        $this->reverse($order)->assertSuccessful();

        $this->reverse($order)->assertStatus(422);
        $this->changeMethod($order, PosPaymentMethod::CARD, '4321')->assertStatus(422);
        $this->postJson('/api/admin/pos-order/change-status/' . $order->id, ['status' => OrderStatus::CONFIRMED])->assertStatus(422);

        $this->assertSame(OrderStatus::CANCELED, $order->refresh()->status);
        $this->assertSame(1, PosSaleChange::count());
        $this->assertSame(1000, $this->soapInStock());
    }

    public function test_staff_without_the_pos_permission_cannot_reverse(): void
    {
        $order = $this->ringUp();
        Sanctum::actingAs($this->user('Stranger', EnumRole::STUFF));

        $this->reverse($order)->assertForbidden();

        $this->assertSame(OrderStatus::CONFIRMED, $order->refresh()->status);
    }

    // ------------------------------------------------------ change method

    public function test_changing_the_method_moves_the_money_with_the_method_pin(): void
    {
        $order = $this->ringUp(PosPaymentMethod::CASH, 300);

        $this->changeMethod($order, PosPaymentMethod::CARD, '4321')->assertSuccessful()
            ->assertJsonPath('data.pos_payment_method', PosPaymentMethod::CARD);

        $order->refresh();
        $this->assertSame('4321', $order->pos_payment_note);
        $this->assertEquals(0, $this->balanceOf(CashAccount::DRAWER), 'the notes were never in the drawer');
        $this->assertEquals(300, $this->balanceOf(CashAccount::POS_CARD));
        $this->assertSame(999, $this->soapInStock(), 'still sold');

        $methods = $this->summary()['sales']['day']['methods'];
        $this->assertEquals([0, 300], [$methods['cash'], $methods['card']]);

        $change = PosSaleChange::sole();
        $this->assertSame([PosSaleChange::METHOD, PosPaymentMethod::CASH, PosPaymentMethod::CARD, '4321', 'Customer paid by card', 300.0],
            [$change->action, $change->from_method, $change->to_method, $change->to_note, $change->reason, $change->amount]);
    }

    public function test_the_branch_pin_cannot_change_a_method(): void
    {
        $order = $this->ringUp(PosPaymentMethod::CASH, 300);

        $this->changeMethod($order, PosPaymentMethod::CARD, '4321', '51920')->assertStatus(422);

        $this->assertSame(PosPaymentMethod::CASH, $order->refresh()->pos_payment_method);
        $this->assertEquals(300, $this->balanceOf(CashAccount::DRAWER));
        $this->assertSame('method_change', CashPinFailure::sole()->action);
    }

    public function test_a_card_method_needs_its_last_four_digits(): void
    {
        $order = $this->ringUp();

        $this->changeMethod($order, PosPaymentMethod::CARD, '12')->assertStatus(422)->assertJsonValidationErrors('pos_payment_note');
    }

    public function test_an_mfs_method_needs_the_transaction_id(): void
    {
        $order = $this->ringUp();

        $this->changeMethod($order, PosPaymentMethod::MOBILE_BANKING, '')->assertStatus(422)->assertJsonValidationErrors('pos_payment_note');
    }

    public function test_the_method_it_already_has_is_refused(): void
    {
        $order = $this->ringUp();

        $this->changeMethod($order, PosPaymentMethod::CASH, null)->assertStatus(422);

        $this->assertSame(0, PosSaleChange::count());
        $this->assertSame(1, CashEntry::count(), 'only the sale');
    }

    // Back to cash: the sale is recorded as paid in full, with no change due.
    public function test_changing_to_cash_records_the_full_amount_received(): void
    {
        $order = $this->ringUp(PosPaymentMethod::CARD, 300);

        $this->changeMethod($order, PosPaymentMethod::CASH, null, '112233', 'Paid in cash after all')->assertSuccessful();

        $order->refresh();
        $this->assertNull($order->pos_payment_note);
        $this->assertEquals(300, $order->pos_received_amount);
        $this->assertEquals(300, $this->balanceOf(CashAccount::DRAWER));
    }

    // -------------------------------------------- the old ways are closed

    public function test_a_pos_sale_cannot_be_deleted(): void
    {
        $order = $this->ringUp();

        $this->deleteJson('/api/admin/pos-order/' . $order->id)->assertStatus(422);

        $this->assertNotNull(Order::find($order->id));
        $this->assertEquals(150, $this->balanceOf(CashAccount::DRAWER));
    }

    public function test_a_pos_sale_cannot_be_cancelled_through_its_status(): void
    {
        $order = $this->ringUp();

        $this->postJson('/api/admin/pos-order/change-status/' . $order->id, ['status' => OrderStatus::CANCELED, 'reason' => 'x'])
            ->assertStatus(422);

        $this->assertSame(OrderStatus::CONFIRMED, $order->refresh()->status);
        $this->assertEquals(150, $this->balanceOf(CashAccount::DRAWER));
    }

    public function test_a_pos_sale_cannot_be_marked_unpaid(): void
    {
        $order = $this->ringUp();

        $this->postJson('/api/admin/pos-order/change-payment-status/' . $order->id, ['payment_status' => PaymentStatus::UNPAID])
            ->assertStatus(422);

        $this->assertSame(PaymentStatus::PAID, $order->refresh()->payment_status);
        $this->assertEquals(150, $this->balanceOf(CashAccount::DRAWER));
    }

    // The online-order screen runs the same code: it is no side door.
    public function test_the_online_order_endpoint_cannot_cancel_a_pos_sale(): void
    {
        $order = $this->ringUp();

        $this->postJson('/api/admin/online-order/change-status/' . $order->id, ['status' => OrderStatus::CANCELED, 'reason' => 'x'])
            ->assertStatus(422);

        $this->assertSame(OrderStatus::CONFIRMED, $order->refresh()->status);
    }

    public function test_a_pos_sale_can_still_be_marked_delivered(): void
    {
        $order = $this->ringUp();

        $this->postJson('/api/admin/pos-order/change-status/' . $order->id, ['status' => OrderStatus::DELIVERED])->assertSuccessful();

        $this->assertSame(OrderStatus::DELIVERED, $order->refresh()->status);
        $this->assertEquals(150, $this->balanceOf(CashAccount::DRAWER));
    }

    // ----------------------------------------------------- the paper trail

    public function test_the_watch_list_shows_reversals_with_reasons_and_method_changes(): void
    {
        $reversed = $this->ringUp();
        $moved    = $this->ringUp(PosPaymentMethod::CASH, 300);
        $this->reverse($reversed)->assertSuccessful();
        $this->changeMethod($moved, PosPaymentMethod::MOBILE_BANKING, 'TRX9')->assertSuccessful();

        $alerts = $this->getJson('/api/admin/cash-calculation/alerts?outlet_id=' . $this->outlet->id)->assertSuccessful()->json('data');

        $this->assertCount(1, $alerts['pos_reversals'], 'a method change is not a sale taken back');
        $this->assertSame('Rung up twice by mistake', $alerts['pos_reversals'][0]['reason']);
        $this->assertCount(1, $alerts['method_changes']);
        $change = $alerts['method_changes'][0];
        $this->assertSame(['Cash', 'Mobile Banking', 'Owner', 'Customer paid by card', $moved->refresh()->order_serial_no],
            [$change['from'], $change['to'], $change['by'], $change['reason'], $change['reference']]);
        $this->assertEquals(300, $change['amount']);
    }

    public function test_the_order_page_lists_its_changes(): void
    {
        $order = $this->ringUp(PosPaymentMethod::CASH, 300);
        $this->changeMethod($order, PosPaymentMethod::CARD, '4321')->assertSuccessful();
        $this->reverse($order, '51920', 'Returned the same day')->assertSuccessful();

        $data = $this->getJson('/api/admin/pos-order/show/' . $order->id)->assertSuccessful()->json('data');

        $this->assertTrue($data['is_reversed']);
        $this->assertSame([PosSaleChange::METHOD, PosSaleChange::REVERSE], array_column($data['pos_changes'], 'action'));
        $this->assertSame(['Cash', 'Card'], [$data['pos_changes'][0]['from_method_name'], $data['pos_changes'][0]['to_method_name']]);
        $this->assertSame('Returned the same day', $data['pos_changes'][1]['reason']);
        $this->assertSame('Owner', $data['pos_changes'][1]['by']);
    }

    public function test_reversals_and_method_changes_alert_the_shop_chat(): void
    {
        $order = $this->ringUp();
        $this->changeMethod($order, PosPaymentMethod::CARD, '4321')->assertSuccessful();
        $this->reverse($order)->assertSuccessful();

        foreach (PosSaleChange::orderBy('id')->pluck('id') as $id) {
            Event::assertDispatched(SendPosSaleChangeTelegram::class, fn(SendPosSaleChangeTelegram $event) => $event->info['change_id'] === $id);
        }
    }

    public function test_the_alert_says_what_changed_who_did_it_and_why(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        Settings::group('telegram')->set(['telegram_status' => Ask::YES, 'telegram_bot_token' => 'TEST-TOKEN', 'telegram_chat_id' => '-100123']);
        $order = $this->ringUp(PosPaymentMethod::CASH, 300);
        $this->changeMethod($order, PosPaymentMethod::CARD, '4321')->assertSuccessful();

        app(PosSaleChangeTelegramNotificationService::class)->send(PosSaleChange::sole()->id);

        $sent = Http::recorded()->map(fn($pair) => $pair[0]->data())->values()->all();
        $this->assertCount(1, $sent);
        $text = $sent[0]['text'];
        $this->assertStringContainsString('<b>POS payment method changed</b>', $text);
        $this->assertStringContainsString('Order No: ' . $order->order_serial_no, $text);
        $this->assertStringContainsString('Branch: Main', $text);
        $this->assertStringContainsString('Amount: ' . AppLibrary::currencyAmountFormat(300), $text);
        $this->assertStringContainsString('Method: Cash → Card (4321)', $text);
        $this->assertStringContainsString('By: Owner', $text);
        $this->assertStringContainsString('Reason: Customer paid by card', $text);
    }
}
