<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Role as EnumRole;
use App\Enums\Source;
use App\Enums\Status;
use App\Events\SendPosOrderSms;
use App\Events\SendPosOrderTelegram;
use App\Libraries\AppLibrary;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * "Sale By": the till credits each sale to an employee, and the Employees page
 * shows how many sales each one made and for how much.
 */
class SalesByTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;
    private Product $product;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([SendPosOrderSms::class, SendPosOrderTelegram::class]);

        // assignRole() looks roles up by id, and App\Enums\Role fixes the ids.
        foreach (['Admin', 'Customer', 'Manager', 'POS Operator', 'Stuff'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'sanctum']);
        }
        foreach (['pos', 'employees', 'employees_show'] as $permission) {
            Permission::findOrCreate($permission, 'sanctum');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $cashier = $this->user('Cashier');
        $cashier->givePermissionTo(['pos', 'employees', 'employees_show']);
        Sanctum::actingAs($cashier);

        $this->outlet = Outlet::create([
            'name'     => 'Main',
            'email'    => 'main@example.test',
            'phone'    => '01700000000',
            'city'     => 'Dhaka',
            'state'    => 'Dhaka',
            'zip_code' => '1212',
            'address'  => 'Main, Dhaka',
            'status'   => Status::ACTIVE,
        ]);
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
    }

    private function user(string $name, ?int $role = null, int $status = Status::ACTIVE): User
    {
        $n    = ++$this->sequence;
        $user = User::create([
            'name'     => $name,
            'username' => 'user' . $n,
            'email'    => 'user' . $n . '@example.test',
            'password' => bcrypt('secret'),
            'status'   => $status,
        ]);
        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function ringUp(array $extra = [])
    {
        return $this->postJson('/api/admin/pos', $extra + [
            'customer_id'         => $this->user('Walk In', EnumRole::CUSTOMER)->id,
            'outlet_id'           => $this->outlet->id,
            'subtotal'            => 150,
            'discount'            => 0,
            'tax'                 => 0,
            'total'               => 150,
            'order_type'          => OrderType::POS,
            'source'              => Source::POS,
            'pos_payment_method'  => PosPaymentMethod::CASH,
            'pos_received_amount' => 200,
            'products'            => json_encode([[
                'product_id'      => $this->product->id,
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
    }

    public function test_a_sale_is_credited_to_the_employee_picked_at_the_till(): void
    {
        $rahim = $this->user('Rahim', EnumRole::POS_OPERATOR);

        $response = $this->ringUp(['sales_by_id' => $rahim->id])->assertSuccessful();

        $this->assertSame($rahim->id, Order::findOrFail($response->json('data.id'))->sales_by_id);
        $this->assertSame('Rahim', $response->json('data.sales_by_name'), 'shown on the POS order details');
    }

    public function test_only_an_active_employee_can_be_credited(): void
    {
        $nobody = [
            'a customer'           => $this->user('Karim', EnumRole::CUSTOMER)->id,
            'an admin'             => $this->user('Owner', EnumRole::ADMIN)->id,
            'an inactive employee' => $this->user('Gone', EnumRole::MANAGER, Status::INACTIVE)->id,
            'a missing user'       => 999999,
        ];

        foreach ($nobody as $who => $id) {
            $response = $this->ringUp(['sales_by_id' => $id]);
            $this->assertSame(422, $response->status(), "crediting $who");
            $this->assertArrayHasKey('sales_by_id', $response->json('errors') ?? [], "crediting $who");
        }

        $this->assertSame(0, Order::count());
    }

    public function test_a_sale_without_sale_by_is_still_accepted(): void
    {
        $response = $this->ringUp()->assertSuccessful();

        $this->assertNull(Order::findOrFail($response->json('data.id'))->sales_by_id);
    }

    public function test_the_till_lists_active_employees_only(): void
    {
        $this->user('Rahim', EnumRole::POS_OPERATOR);
        $this->user('Nadia', EnumRole::MANAGER);
        $this->user('Gone', EnumRole::STUFF, Status::INACTIVE);
        $this->user('Owner', EnumRole::ADMIN);
        $this->user('Karim', EnumRole::CUSTOMER);

        $names = array_column($this->getJson('/api/admin/pos/employees')->assertOk()->json('data'), 'name');

        $this->assertSame(['Nadia', 'Rahim'], $names);
    }

    public function test_the_employees_page_counts_each_employees_paid_sales_and_their_total(): void
    {
        $rahim    = $this->user('Rahim', EnumRole::POS_OPERATOR);
        $nadia    = $this->user('Nadia', EnumRole::MANAGER);
        $this->user('Sumi', EnumRole::STUFF);
        $customer = $this->user('Karim', EnumRole::CUSTOMER);

        $sale = fn(User $by, float $total, int $status = OrderStatus::CONFIRMED, int $payment = PaymentStatus::PAID) => Order::forceCreate([
            'user_id'        => $customer->id,
            'sales_by_id'    => $by->id,
            'subtotal'       => $total,
            'total'          => $total,
            'status'         => $status,
            'payment_status' => $payment,
            'order_type'     => OrderType::POS,
            'source'         => Source::POS,
        ]);

        $sale($rahim, 100);
        $sale($rahim, 250.5);
        $sale($rahim, 999, OrderStatus::CANCELED);
        $sale($rahim, 888, OrderStatus::REJECTED);
        $sale($rahim, 50, OrderStatus::PENDING, PaymentStatus::UNPAID);
        $sale($nadia, 70);

        $list = collect($this->getJson('/api/admin/employee?paginate=0')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame(2, $list['Rahim']['sales_count']);
        $this->assertSame(AppLibrary::flatAmountFormat(350.5), $list['Rahim']['sales_amount']);
        $this->assertSame(1, $list['Nadia']['sales_count']);
        $this->assertSame(AppLibrary::flatAmountFormat(70), $list['Nadia']['sales_amount']);
        $this->assertSame(0, $list['Sumi']['sales_count']);
        $this->assertSame(AppLibrary::flatAmountFormat(0), $list['Sumi']['sales_amount']);

        $show = $this->getJson('/api/admin/employee/show/' . $rahim->id)->assertOk()->json('data');

        $this->assertSame(2, $show['sales_count']);
        $this->assertSame(AppLibrary::currencyAmountFormat(350.5), $show['sales_currency_amount']);
    }
}
