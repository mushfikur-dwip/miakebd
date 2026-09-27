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
use App\Models\CashEntry;
use App\Models\CashPinFailure;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The Cash Calculation page: each branch's shop cash, bKash and Nagad agent
 * money as an append-only ledger. These pin the rules that stop money going
 * missing quietly - till sales post and reverse themselves, money out needs
 * the branch PIN, counts are blind, and nothing can be edited away.
 */
class CashCalculationTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;
    private Product $product;
    private User $owner;
    private User $cashier;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([SendPosOrderSms::class, SendPosOrderTelegram::class, SendOrderMail::class, SendOrderSms::class, SendOrderPush::class]);

        // assignRole() looks roles up by id, and App\Enums\Role fixes the ids.
        foreach (['Admin', 'Customer', 'Manager', 'POS Operator', 'Stuff'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'sanctum']);
        }
        foreach (['pos', 'pos-orders', 'cash-calculation', 'cash-calculation_balance'] as $permission) {
            Permission::findOrCreate($permission, 'sanctum');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->owner = $this->user('Owner', EnumRole::MANAGER);
        $this->owner->givePermissionTo(['pos', 'pos-orders', 'cash-calculation', 'cash-calculation_balance']);

        $this->cashier = $this->user('Cashier', EnumRole::POS_OPERATOR);
        $this->cashier->givePermissionTo(['pos', 'cash-calculation']);

        $this->outlet  = $this->outlet('Main');
        $this->product = Product::create([
            'name'            => 'Soap',
            'slug'            => 'soap',
            'sku'             => 'SOAP1',
            'status'          => Status::ACTIVE,
            'can_purchasable' => Ask::YES,
            'buying_price'    => 100,
            'selling_price'   => 150,
            'variation_price' => 150,
        ]);

        Sanctum::actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------ helpers

    private function user(string $name, ?int $role = null): User
    {
        $n    = ++$this->sequence;
        $user = User::create([
            'name'     => $name,
            'username' => 'user' . $n,
            'email'    => 'user' . $n . '@example.test',
            'password' => bcrypt('secret'),
            'status'   => Status::ACTIVE,
        ]);
        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function outlet(string $name): Outlet
    {
        return Outlet::create([
            'name'     => $name,
            'email'    => str($name)->slug()->value() . '@example.test',
            'phone'    => '01700000000',
            'city'     => 'Dhaka',
            'state'    => 'Dhaka',
            'zip_code' => '1212',
            'address'  => $name . ', Dhaka',
            'status'   => Status::ACTIVE,
        ]);
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
                'product_id'      => $this->product->id,
                'variation_id'    => 0,
                'variation_names' => null,
                'sku'             => 'SOAP1',
                'price'           => $total,
                'quantity'        => 1,
                'discount'        => 0,
                'total_tax'       => 0,
                'subtotal'        => $total,
                'total'           => $total,
                'taxes'           => [],
            ]]),
        ])->assertSuccessful();

        return Order::findOrFail($response->json('data.id'));
    }

    private function drawer(?Outlet $outlet = null): float
    {
        return $this->balanceOf(CashAccount::DRAWER, $outlet);
    }

    private function balanceOf(int $account, ?Outlet $outlet = null): float
    {
        return round((float) CashEntry::where('outlet_id', ($outlet ?? $this->outlet)->id)
            ->where('account', $account)->sum('amount'), 2);
    }

    private function add(float $amount, int $account = CashAccount::DRAWER, ?Outlet $outlet = null)
    {
        return $this->postJson('/api/admin/cash-calculation/add', [
            'outlet_id' => ($outlet ?? $this->outlet)->id,
            'account'   => $account,
            'amount'    => $amount,
            'note'      => 'Opening balance',
        ]);
    }

    private function withdraw(float $amount, ?string $pin = '51920', int $account = CashAccount::DRAWER, ?Outlet $outlet = null)
    {
        return $this->postJson('/api/admin/cash-calculation/withdraw', [
            'outlet_id' => ($outlet ?? $this->outlet)->id,
            'account'   => $account,
            'amount'    => $amount,
            'party'     => 'Owner',
            'note'      => 'Bank deposit',
            'pin'       => $pin,
        ]);
    }

    private function enableMfs(): void
    {
        $this->postJson('/api/admin/cash-calculation/mfs-toggle', [
            'outlet_id' => $this->outlet->id,
            'enabled'   => true,
            'pin'       => '51920',
        ])->assertSuccessful();
        $this->outlet->refresh();
    }

    private function mfs(string $kind, float $amount, string $provider = 'bkash')
    {
        return $this->postJson('/api/admin/cash-calculation/mfs', [
            'outlet_id' => $this->outlet->id,
            'provider'  => $provider,
            'kind'      => $kind,
            'amount'    => $amount,
            'party'     => '01711111111',
        ]);
    }

    // ------------------------------------------------------------ POS

    public function test_a_cash_sale_posts_its_total_to_the_branch_drawer(): void
    {
        $order = $this->ringUp();

        $entry = CashEntry::sole();
        $this->assertSame(CashEntryType::POS_SALE, $entry->type);
        $this->assertSame(CashAccount::DRAWER, $entry->account);
        $this->assertSame($this->outlet->id, $entry->outlet_id);
        $this->assertSame($order->id, $entry->order_id);
        $this->assertEquals(150, $entry->amount);
        $this->assertEquals(150, $entry->balance_after);
    }

    public function test_card_mfs_and_other_sales_never_touch_the_drawer(): void
    {
        $this->ringUp(PosPaymentMethod::CARD);
        $this->ringUp(PosPaymentMethod::MOBILE_BANKING);
        $this->ringUp(PosPaymentMethod::OTHER);

        $this->assertSame(0, CashEntry::count());
    }

    public function test_cancelling_a_cash_sale_reverses_it_and_restoring_it_posts_it_again(): void
    {
        $order = $this->ringUp();

        $this->postJson('/api/admin/pos-order/change-status/' . $order->id, [
            'status' => OrderStatus::CANCELED,
            'reason' => 'Customer changed mind',
        ])->assertSuccessful();

        $this->assertEquals(0, $this->drawer());
        $reversal = CashEntry::where('type', CashEntryType::POS_SALE_REVERSAL)->sole();
        $this->assertEquals(-150, $reversal->amount);
        $this->assertSame($this->owner->id, $reversal->created_by, 'recorded against whoever cancelled it');

        // Saving the cancelled order again posts nothing more.
        $order->refresh()->update(['reason' => 'Changed note']);
        $this->assertSame(2, CashEntry::count());

        $order->refresh()->update(['status' => OrderStatus::CONFIRMED]);
        $this->assertEquals(150, $this->drawer());
        $this->assertSame(3, CashEntry::count());
    }

    public function test_deleting_a_cash_sale_reverses_it(): void
    {
        $order = $this->ringUp();

        $this->deleteJson('/api/admin/pos-order/' . $order->id)->assertSuccessful();

        $this->assertNull(Order::find($order->id));
        $this->assertEquals(0, $this->drawer());
        $this->assertSame(1, CashEntry::where('type', CashEntryType::POS_SALE_REVERSAL)->count());
    }

    public function test_marking_a_cash_sale_unpaid_reverses_it(): void
    {
        $order = $this->ringUp();

        $this->postJson('/api/admin/pos-order/change-payment-status/' . $order->id, [
            'payment_status' => PaymentStatus::UNPAID,
        ])->assertSuccessful();

        $this->assertEquals(0, $this->drawer());
    }

    public function test_the_existing_pos_sale_is_unchanged(): void
    {
        $order = $this->ringUp();

        $this->assertSame(OrderStatus::CONFIRMED, (int) $order->status);
        $this->assertSame(PaymentStatus::PAID, (int) $order->payment_status);
        $this->assertNotEmpty($order->order_serial_no);
        $this->assertSame(1, $order->orderProducts()->count());
    }

    // --------------------------------------------------------- the ledger

    public function test_entries_can_never_be_edited_or_deleted(): void
    {
        $this->add(1000)->assertSuccessful();
        $entry = CashEntry::sole();

        try {
            $entry->update(['amount' => 1]);
            $this->fail('An entry was edited.');
        } catch (LogicException) {
        }

        try {
            $entry->delete();
            $this->fail('An entry was deleted.');
        } catch (LogicException) {
        }

        $this->assertEquals(1000, CashEntry::sole()->amount);
    }

    public function test_the_statement_opens_on_the_previous_days_closing(): void
    {
        Carbon::setTestNow('2026-09-26 18:00:00');
        $this->add(1000)->assertSuccessful();

        Carbon::setTestNow('2026-09-27 10:00:00');
        $this->ringUp();
        $this->add(200)->assertSuccessful();
        $this->withdraw(300)->assertSuccessful();

        $drawer = $this->getJson('/api/admin/cash-calculation/summary?outlet_id=' . $this->outlet->id)
            ->assertSuccessful()
            ->json('data.drawer');

        $this->assertEquals(1000, $drawer['opening']);
        $this->assertEquals(150, $drawer['pos_sales']);
        $this->assertEquals(200, $drawer['added']);
        $this->assertEquals(-300, $drawer['withdrawn']);
        $this->assertEquals(1050, $drawer['closing']);

        $yesterday = $this->getJson('/api/admin/cash-calculation/summary?outlet_id=' . $this->outlet->id . '&date=2026-09-26')
            ->json('data.drawer');
        $this->assertEquals(0, $yesterday['opening']);
        $this->assertEquals(1000, $yesterday['closing']);
    }

    public function test_each_branch_keeps_its_own_money(): void
    {
        $other = $this->outlet('Second');

        $this->add(1000)->assertSuccessful();
        $this->add(400, CashAccount::DRAWER, $other)->assertSuccessful();

        $this->assertEquals(1000, $this->drawer());
        $this->assertEquals(400, $this->drawer($other));
    }

    // -------------------------------------------------------------- PIN

    public function test_a_branch_that_never_changed_its_pin_accepts_the_default(): void
    {
        $this->add(1000)->assertSuccessful();

        $this->withdraw(400)->assertSuccessful();

        $entry = CashEntry::where('type', CashEntryType::WITHDRAW)->sole();
        $this->assertEquals(-400, $entry->amount);
        $this->assertSame('Owner', $entry->party);
        $this->assertEquals(600, $this->drawer());
    }

    public function test_a_wrong_pin_is_refused_and_logged(): void
    {
        $this->add(1000)->assertSuccessful();

        $this->withdraw(400, '11111')->assertStatus(422)->assertJsonPath('status', false);

        $this->assertEquals(1000, $this->drawer());
        $failure = CashPinFailure::sole();
        $this->assertSame($this->owner->id, $failure->user_id);
        $this->assertSame('withdraw', $failure->action);
    }

    public function test_five_wrong_pins_lock_that_person_out(): void
    {
        $this->add(1000)->assertSuccessful();

        foreach (range(1, 4) as $try) {
            $this->withdraw(10, '00000')->assertStatus(422);
        }
        $this->withdraw(10, '00000')->assertStatus(429);

        // Even the right PIN is refused while locked.
        $this->withdraw(10, '51920')->assertStatus(429);
        $this->assertEquals(1000, $this->drawer());

        Carbon::setTestNow(now()->addMinutes(16));
        $this->withdraw(10, '51920')->assertSuccessful();
    }

    public function test_ten_wrong_pins_on_one_branch_lock_it_for_everyone(): void
    {
        $this->add(1000)->assertSuccessful();

        foreach (range(1, 5) as $n) {
            $guesser = $this->user('Guesser ' . $n, EnumRole::POS_OPERATOR);
            $guesser->givePermissionTo('cash-calculation');
            Sanctum::actingAs($guesser);
            $this->withdraw(10, '00000');
            $this->withdraw(10, '00001');
        }

        Sanctum::actingAs($this->owner);
        $this->withdraw(10, '51920')->assertStatus(429);
    }

    public function test_a_branch_pin_change_needs_its_current_pin_and_leaves_other_branches_alone(): void
    {
        $other = $this->outlet('Second');
        $this->add(1000)->assertSuccessful();
        $this->add(1000, CashAccount::DRAWER, $other)->assertSuccessful();

        $this->postJson('/api/admin/cash-calculation/pin', [
            'outlet_id'            => $this->outlet->id,
            'current_pin'          => '51920',
            'new_pin'              => '777888',
            'new_pin_confirmation' => '777888',
        ])->assertSuccessful();

        $this->withdraw(10, '51920')->assertStatus(422);
        $this->withdraw(10, '777888')->assertSuccessful();
        $this->withdraw(10, '51920', CashAccount::DRAWER, $other)->assertSuccessful();
    }

    public function test_a_pin_change_with_the_wrong_current_pin_is_refused(): void
    {
        $this->postJson('/api/admin/cash-calculation/pin', [
            'outlet_id'            => $this->outlet->id,
            'current_pin'          => '12345',
            'new_pin'              => '777888',
            'new_pin_confirmation' => '777888',
        ])->assertStatus(422);

        $this->add(100)->assertSuccessful();
        $this->withdraw(10, '51920')->assertSuccessful();
    }

    public function test_a_withdrawal_cannot_exceed_the_balance(): void
    {
        $this->add(100)->assertSuccessful();

        $this->withdraw(150)->assertStatus(422)->assertJsonPath('message', 'Not enough balance in Shop cash. Available: 100.00.');
        $this->assertEquals(100, $this->drawer());
    }

    public function test_a_cashier_is_not_told_the_balance_when_a_withdrawal_is_too_big(): void
    {
        $this->add(100)->assertSuccessful();
        Sanctum::actingAs($this->cashier);

        $this->withdraw(150)->assertStatus(422)->assertJsonPath('message', 'Not enough balance in Shop cash.');
    }

    // -------------------------------------------------------- bKash / Nagad

    public function test_agent_cash_in_and_cash_out_move_sim_and_cash_in_opposite_directions(): void
    {
        $this->enableMfs();
        $this->add(5000, CashAccount::BKASH_SIM)->assertSuccessful();

        $this->mfs('cash_in', 1000)->assertSuccessful();
        $this->assertEquals(4000, $this->balanceOf(CashAccount::BKASH_SIM));
        $this->assertEquals(1000, $this->balanceOf(CashAccount::BKASH_CASH));

        $this->mfs('cash_out', 500)->assertSuccessful();
        $this->assertEquals(4500, $this->balanceOf(CashAccount::BKASH_SIM));
        $this->assertEquals(500, $this->balanceOf(CashAccount::BKASH_CASH));

        // SIM + cash never moves through agent business, and nothing leaks
        // into the shop drawer, Nagad or Recharge.
        $this->assertEquals(5000, $this->balanceOf(CashAccount::BKASH_SIM) + $this->balanceOf(CashAccount::BKASH_CASH));
        $this->assertEquals(0, $this->drawer());
        $this->assertEquals(0, $this->balanceOf(CashAccount::NAGAD_SIM));
        $this->assertEquals(0, $this->balanceOf(CashAccount::RECHARGE_SIM));

        $cashIn = CashEntry::where('type', CashEntryType::MFS_CASH_IN)->orderBy('id')->get();
        $this->assertCount(2, $cashIn);
        $this->assertNotNull($cashIn[0]->group_ref);
        $this->assertSame($cashIn[0]->group_ref, $cashIn[1]->group_ref);
    }

    public function test_recharge_is_its_own_service_with_its_own_balance(): void
    {
        $this->enableMfs();
        $this->add(2000, CashAccount::RECHARGE_SIM)->assertSuccessful();

        $this->mfs('recharge', 100, 'recharge')->assertSuccessful();

        $this->assertEquals(1900, $this->balanceOf(CashAccount::RECHARGE_SIM));
        $this->assertEquals(100, $this->balanceOf(CashAccount::RECHARGE_CASH));
        $this->assertEquals(0, $this->balanceOf(CashAccount::BKASH_SIM));
        $this->assertEquals(0, $this->balanceOf(CashAccount::BKASH_CASH));
        $this->assertEquals(0, $this->drawer());
    }

    public function test_bkash_and_nagad_no_longer_take_a_recharge(): void
    {
        $this->enableMfs();
        $this->add(1000, CashAccount::BKASH_SIM)->assertSuccessful();

        $this->mfs('recharge', 100, 'bkash')->assertStatus(422);
        $this->mfs('recharge', 100, 'nagad')->assertStatus(422);
        $this->mfs('cash_in', 100, 'recharge')->assertStatus(422);

        $this->assertSame(1, CashEntry::count());
    }

    public function test_a_cash_in_or_recharge_goes_through_whatever_the_sim_balance_shows(): void
    {
        $this->enableMfs();
        $this->add(500, CashAccount::BKASH_SIM)->assertSuccessful();

        // More than the SIM shows, and a recharge with no balance entered at all.
        $this->mfs('cash_in', 600)->assertSuccessful();
        $this->mfs('recharge', 60, 'recharge')->assertSuccessful();

        $this->assertEquals(-100, $this->balanceOf(CashAccount::BKASH_SIM));
        $this->assertEquals(600, $this->balanceOf(CashAccount::BKASH_CASH));
        $this->assertEquals(-60, $this->balanceOf(CashAccount::RECHARGE_SIM));
        $this->assertEquals(60, $this->balanceOf(CashAccount::RECHARGE_CASH));

        // The next SIM count puts the figure right, in the counter's name.
        $this->postJson('/api/admin/cash-calculation/count', [
            'outlet_id' => $this->outlet->id,
            'account'   => CashAccount::BKASH_SIM,
            'counted'   => 4900,
        ])->assertSuccessful()->assertJsonPath('data.variance', 5000);
        $this->assertEquals(4900, $this->balanceOf(CashAccount::BKASH_SIM));
    }

    public function test_a_cash_out_larger_than_the_cash_box_is_refused(): void
    {
        $this->enableMfs();
        $this->add(300, CashAccount::NAGAD_CASH)->assertSuccessful();

        $this->mfs('cash_out', 400, 'nagad')->assertStatus(422);
        $this->assertEquals(300, $this->balanceOf(CashAccount::NAGAD_CASH));
    }

    public function test_mfs_is_refused_while_the_branch_has_it_off(): void
    {
        $this->mfs('cash_in', 100)->assertStatus(422);
        $this->add(100, CashAccount::BKASH_SIM)->assertStatus(422);

        $this->assertSame(0, CashEntry::count());
    }

    public function test_mfs_needs_the_pin_to_switch_on(): void
    {
        $this->postJson('/api/admin/cash-calculation/mfs-toggle', [
            'outlet_id' => $this->outlet->id,
            'enabled'   => true,
            'pin'       => '99999',
        ])->assertStatus(422);

        $this->assertFalse($this->outlet->refresh()->mfs_enabled);
    }

    public function test_mfs_cannot_be_switched_off_while_it_holds_money(): void
    {
        $this->enableMfs();
        $this->add(100, CashAccount::NAGAD_SIM)->assertSuccessful();

        $this->postJson('/api/admin/cash-calculation/mfs-toggle', [
            'outlet_id' => $this->outlet->id,
            'enabled'   => false,
            'pin'       => '51920',
        ])->assertStatus(422);

        $this->assertTrue($this->outlet->refresh()->mfs_enabled);
    }

    public function test_the_grand_total_is_the_only_place_the_three_are_added_up(): void
    {
        $this->enableMfs();
        $this->add(1000)->assertSuccessful();
        $this->add(2000, CashAccount::BKASH_SIM)->assertSuccessful();
        $this->add(500, CashAccount::NAGAD_SIM)->assertSuccessful();
        $this->add(400, CashAccount::RECHARGE_SIM)->assertSuccessful();
        $this->mfs('cash_in', 300)->assertSuccessful();
        $this->mfs('recharge', 50, 'recharge')->assertSuccessful();

        $data = $this->getJson('/api/admin/cash-calculation/summary?outlet_id=' . $this->outlet->id)->json('data');

        $this->assertEquals(1000, $data['drawer']['closing']);
        $this->assertEquals(1700, $data['mfs']['bkash']['sim']['closing']);
        $this->assertEquals(300, $data['mfs']['bkash']['cash']['closing']);
        $this->assertEquals(-300, $data['mfs']['bkash']['sim']['cash_in']);
        $this->assertEquals(2000, $data['mfs']['bkash']['total_closing']);
        $this->assertEquals(500, $data['mfs']['nagad']['total_closing']);
        $this->assertEquals(350, $data['mfs']['recharge']['sim']['closing']);
        $this->assertEquals(-50, $data['mfs']['recharge']['sim']['recharge']);
        $this->assertEquals(400, $data['mfs']['recharge']['total_closing']);
        $this->assertEquals(1350, $data['grand_total']['notes']);
        $this->assertEquals(2550, $data['grand_total']['emoney']);
        $this->assertEquals(3900, $data['grand_total']['total']);
    }

    // -------------------------------------------------------------- counts

    public function test_a_cashiers_count_is_blind_and_posts_the_shortage_in_their_name(): void
    {
        $this->add(1000)->assertSuccessful();
        Sanctum::actingAs($this->cashier);

        $response = $this->postJson('/api/admin/cash-calculation/count', [
            'outlet_id'     => $this->outlet->id,
            'account'       => CashAccount::DRAWER,
            'denominations' => ['500' => 1, '100' => 4, '20' => 2],
        ])->assertSuccessful();

        $this->assertNull($response->json('data'), 'the cashier is not told the result');

        $variance = CashEntry::where('type', CashEntryType::COUNT_VARIANCE)->sole();
        $this->assertEquals(-60, $variance->amount);
        $this->assertSame($this->cashier->id, $variance->created_by);
        $this->assertEquals(940, $this->drawer());

        $mine = $this->getJson('/api/admin/cash-calculation/entries?outlet_id=' . $this->outlet->id)->json('data');
        $this->assertSame([], $mine, 'their own list does not show the difference either');
    }

    public function test_an_owners_count_shows_the_result(): void
    {
        $this->add(1000)->assertSuccessful();

        $this->postJson('/api/admin/cash-calculation/count', [
            'outlet_id' => $this->outlet->id,
            'account'   => CashAccount::DRAWER,
            'counted'   => 1050,
        ])->assertSuccessful()
            ->assertJsonPath('data.expected', 1000)
            ->assertJsonPath('data.variance', 50);

        $this->assertEquals(1050, $this->drawer());
    }

    public function test_an_exact_count_posts_nothing(): void
    {
        $this->add(1000)->assertSuccessful();

        $this->postJson('/api/admin/cash-calculation/count', [
            'outlet_id' => $this->outlet->id,
            'account'   => CashAccount::DRAWER,
            'counted'   => 1000,
        ])->assertSuccessful();

        $this->assertSame(1, CashEntry::count());
    }

    // ----------------------------------------------------------- reversals

    public function test_a_reversal_needs_the_pin_and_undoes_both_legs(): void
    {
        $this->enableMfs();
        $this->add(1000, CashAccount::BKASH_SIM)->assertSuccessful();
        $this->mfs('cash_in', 400)->assertSuccessful();
        $leg = CashEntry::where('type', CashEntryType::MFS_CASH_IN)->first();

        $this->postJson('/api/admin/cash-calculation/reverse/' . $leg->id, ['note' => 'Typed wrong', 'pin' => '00000'])
            ->assertStatus(422);
        $this->assertEquals(600, $this->balanceOf(CashAccount::BKASH_SIM));

        $this->postJson('/api/admin/cash-calculation/reverse/' . $leg->id, ['note' => 'Typed wrong', 'pin' => '51920'])
            ->assertSuccessful();
        $this->assertEquals(1000, $this->balanceOf(CashAccount::BKASH_SIM));
        $this->assertEquals(0, $this->balanceOf(CashAccount::BKASH_CASH));
        $this->assertSame(2, CashEntry::where('type', CashEntryType::REVERSAL)->count());

        $this->postJson('/api/admin/cash-calculation/reverse/' . $leg->id, ['note' => 'Again', 'pin' => '51920'])
            ->assertStatus(422);
    }

    public function test_a_till_sale_cannot_be_reversed_from_the_cash_page(): void
    {
        $this->ringUp();

        $this->postJson('/api/admin/cash-calculation/reverse/' . CashEntry::sole()->id, ['note' => 'x', 'pin' => '51920'])
            ->assertStatus(422);
        $this->assertEquals(150, $this->drawer());
    }

    public function test_a_transfer_moves_money_between_two_accounts_of_the_branch(): void
    {
        $this->enableMfs();
        $this->add(1000)->assertSuccessful();

        $this->postJson('/api/admin/cash-calculation/transfer', [
            'outlet_id'    => $this->outlet->id,
            'from_account' => CashAccount::DRAWER,
            'to_account'   => CashAccount::BKASH_CASH,
            'amount'       => 250,
            'note'         => 'Change for bKash',
            'pin'          => '51920',
        ])->assertSuccessful();

        $this->assertEquals(750, $this->drawer());
        $this->assertEquals(250, $this->balanceOf(CashAccount::BKASH_CASH));
    }

    // -------------------------------------------------------------- access

    public function test_a_cashier_sees_no_balances(): void
    {
        $this->add(1000)->assertSuccessful();
        Sanctum::actingAs($this->cashier);

        $summary = $this->getJson('/api/admin/cash-calculation/summary?outlet_id=' . $this->outlet->id)
            ->assertSuccessful()
            ->json('data');
        $this->assertFalse($summary['can_see_balance']);
        $this->assertArrayNotHasKey('drawer', $summary);
        $this->assertArrayNotHasKey('grand_total', $summary);

        $this->add(50)->assertSuccessful()->assertJsonPath('data.0.balance_after', null);

        $mine = $this->getJson('/api/admin/cash-calculation/entries?outlet_id=' . $this->outlet->id)->json('data');
        $this->assertCount(1, $mine, 'only the entry they made themselves');
        $this->assertNull($mine[0]['balance_after']);

        $this->getJson('/api/admin/cash-calculation/alerts?outlet_id=' . $this->outlet->id)->assertForbidden();
    }

    public function test_customers_guests_and_staff_without_the_permission_are_kept_out(): void
    {
        $customer = $this->user('Shopper', EnumRole::CUSTOMER);
        $guest    = $this->user('Guest', EnumRole::CUSTOMER);
        $guest->forceFill(['is_guest' => Ask::YES])->save();
        $staff    = $this->user('Staff', EnumRole::STUFF);

        foreach ([$customer, $guest, $staff] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/admin/cash-calculation/summary?outlet_id=' . $this->outlet->id)->assertForbidden();
            $this->getJson('/api/admin/cash-calculation/outlets')->assertForbidden();
            $this->add(100)->assertForbidden();
            $this->withdraw(10)->assertForbidden();
        }

        $this->assertSame(0, CashEntry::count());
    }

    public function test_alerts_show_reversed_sales_wrong_pins_and_the_default_pin(): void
    {
        $order = $this->ringUp();
        $this->deleteJson('/api/admin/pos-order/' . $order->id)->assertSuccessful();

        Sanctum::actingAs($this->cashier);
        $this->withdraw(10, '00000');
        Sanctum::actingAs($this->owner);

        $alerts = $this->getJson('/api/admin/cash-calculation/alerts?outlet_id=' . $this->outlet->id)
            ->assertSuccessful()
            ->json('data');

        $this->assertTrue($alerts['default_pin']);
        $this->assertCount(1, $alerts['pos_reversals']);
        $this->assertEquals(-150, $alerts['pos_reversals'][0]['amount']);
        $this->assertSame('Owner', $alerts['pos_reversals'][0]['by']);
        $this->assertSame('Cashier', $alerts['pin_failures'][0]['by']);
        $this->assertSame(1, $alerts['pin_failures'][0]['count']);
    }
}
