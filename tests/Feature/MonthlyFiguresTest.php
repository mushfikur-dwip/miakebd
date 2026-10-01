<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Role as EnumRole;
use App\Enums\Source;
use App\Enums\Status;
use App\Libraries\AppLibrary;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Admin figures start fresh each calendar month: the pages send the month as
 * from_date/to_date, and every total counts only what happened inside it.
 * Without dates the endpoints keep returning all-time figures.
 */
class MonthlyFiguresTest extends TestCase
{
    use RefreshDatabase;

    private const OCTOBER = 'from_date=2026-10-01&to_date=2026-10-31';

    private User $customer;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // assignRole() looks roles up by id, and App\Enums\Role fixes the ids.
        foreach (['Admin', 'Customer', 'Manager', 'POS Operator', 'Stuff'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'sanctum']);
        }
        $permissions = ['employees', 'employees_show', 'dashboard', 'products-report'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'sanctum');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $owner = $this->user('Owner');
        $owner->givePermissionTo($permissions);
        Sanctum::actingAs($owner);

        $this->customer = $this->user('Karim', EnumRole::CUSTOMER);
    }

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

    private function order(string $datetime, float $total, array $extra = []): Order
    {
        return Order::forceCreate($extra + [
            'user_id'        => $this->customer->id,
            'subtotal'       => $total,
            'total'          => $total,
            'status'         => OrderStatus::CONFIRMED,
            'payment_status' => PaymentStatus::PAID,
            'order_type'     => OrderType::POS,
            'source'         => Source::POS,
            'order_datetime' => $datetime,
        ]);
    }

    /** Rahim's paid sales straddle both edges of October; one October sale was cancelled. */
    private function rahimsSales(): User
    {
        $rahim = $this->user('Rahim', EnumRole::POS_OPERATOR);
        $by    = ['sales_by_id' => $rahim->id];

        $this->order('2026-09-30 23:59:59', 100, $by);
        $this->order('2026-10-01 00:00:00', 200, $by);
        $this->order('2026-10-31 23:59:59', 300, $by);
        $this->order('2026-11-01 00:00:00', 400, $by);
        $this->order('2026-10-15 12:00:00', 999, $by + ['status' => OrderStatus::CANCELED]);

        return $rahim;
    }

    public function test_the_employees_list_counts_only_sales_inside_the_month(): void
    {
        $this->rahimsSales();

        $list = collect($this->getJson('/api/admin/employee?paginate=0&' . self::OCTOBER)->assertOk()->json('data'))
            ->keyBy('name');

        $this->assertSame(2, $list['Rahim']['sales_count']);
        $this->assertSame(AppLibrary::flatAmountFormat(500), $list['Rahim']['sales_amount']);
    }

    public function test_an_employees_page_shows_only_sales_inside_the_month(): void
    {
        $rahim = $this->rahimsSales();

        $show = $this->getJson('/api/admin/employee/show/' . $rahim->id . '?' . self::OCTOBER)->assertOk()->json('data');

        $this->assertSame(2, $show['sales_count']);
        $this->assertSame(AppLibrary::currencyAmountFormat(500), $show['sales_currency_amount']);
    }

    /** Paid orders on both edges of October, one unpaid, and one paid but not yet delivered. */
    private function dashboardOrders(): void
    {
        $delivered = ['status' => OrderStatus::DELIVERED];

        $this->order('2026-09-30 23:59:59', 100, $delivered);
        $this->order('2026-10-01 00:00:00', 200, $delivered);
        $this->order('2026-10-31 23:59:59', 70);
        $this->order('2026-10-20 10:00:00', 50, ['status' => OrderStatus::PENDING, 'payment_status' => PaymentStatus::UNPAID]);
        $this->order('2026-11-01 00:00:00', 400, $delivered);
    }

    public function test_the_dashboard_earnings_and_orders_cards_count_only_the_month(): void
    {
        $this->dashboardOrders();
        $october = 'first_date=2026-10-01&last_date=2026-10-31';

        $this->assertSame(AppLibrary::currencyAmountFormat(270),
            $this->getJson('/api/admin/dashboard/total-sales?' . $october)->assertOk()->json('data.total_sales'));
        $this->assertSame(1,
            $this->getJson('/api/admin/dashboard/total-orders?' . $october)->assertOk()->json('data.total_orders'));
    }

    public function test_the_dashboard_cards_stay_all_time_without_dates(): void
    {
        $this->dashboardOrders();

        $this->assertSame(AppLibrary::currencyAmountFormat(770),
            $this->getJson('/api/admin/dashboard/total-sales')->assertOk()->json('data.total_sales'));
        $this->assertSame(3,
            $this->getJson('/api/admin/dashboard/total-orders')->assertOk()->json('data.total_orders'));
    }

    private function product(string $name, ProductCategory $category, string $addedAt): Product
    {
        return Product::forceCreate([
            'name'                => $name,
            'slug'                => Str::slug($name),
            'sku'                 => strtoupper($name),
            'status'              => Status::ACTIVE,
            'can_purchasable'     => Ask::YES,
            'buying_price'        => 100,
            'selling_price'       => 150,
            'variation_price'     => 150,
            'product_category_id' => $category->id,
            'created_at'          => $addedAt,
            'updated_at'          => $addedAt,
        ]);
    }

    /** One order and its stock lines, written the way OrderService records a sale. */
    private function sell(string $datetime, array $lines): void
    {
        $order = $this->order($datetime, 0);
        foreach ($lines as [$product, $quantity]) {
            Stock::forceCreate([
                'product_id' => $product->id,
                'model_type' => Order::class,
                'model_id'   => $order->id,
                'item_type'  => Product::class,
                'item_id'    => $product->id,
                'quantity'   => -$quantity,
                'status'     => Status::ACTIVE,
                'created_at' => $datetime,
                'updated_at' => $datetime,
            ]);
        }
    }

    /** Soap and Cream sell in October, Oil only in November; Serum was added in October but never sold. */
    private function productSales(): void
    {
        $skin = ProductCategory::create(['name' => 'Skin Care', 'slug' => 'skin-care', 'status' => Status::ACTIVE]);
        $hair = ProductCategory::create(['name' => 'Hair Care', 'slug' => 'hair-care', 'status' => Status::ACTIVE]);

        $soap  = $this->product('Soap', $skin, '2026-01-10 09:00:00');
        $cream = $this->product('Cream', $skin, '2026-01-10 09:00:00');
        $oil   = $this->product('Oil', $hair, '2026-01-10 09:00:00');
        $this->product('Serum', $hair, '2026-10-05 09:00:00');

        $this->sell('2026-09-30 23:59:59', [[$soap, 2]]);
        $this->sell('2026-10-01 00:00:00', [[$soap, 3], [$cream, 1]]);
        $this->sell('2026-11-01 00:00:00', [[$oil, 5]]);
    }

    /** name => units sold, in the report's order. */
    private function productReportRows(string $query): array
    {
        return collect($this->getJson('/api/admin/products-report?paginate=0' . $query)->assertOk()->json('data'))
            ->mapWithKeys(fn($row) => [$row['name'] => $row['order']])->all();
    }

    public function test_the_products_report_lists_only_products_sold_in_the_month_with_that_months_units(): void
    {
        $this->productSales();

        $this->assertSame(['Soap' => 3, 'Cream' => 1], $this->productReportRows('&' . self::OCTOBER));
    }

    public function test_the_products_report_totals_count_only_the_months_sales(): void
    {
        $this->productSales();

        $overview = $this->getJson('/api/admin/products-report/overview?' . self::OCTOBER)->assertOk()->json('data');

        $this->assertSame(['total_products' => 2, 'total_categories' => 1, 'total_sold_quantity' => 4], $overview);
    }

    public function test_the_products_report_stays_all_time_without_dates(): void
    {
        $this->productSales();

        $this->assertSame(['Soap' => 5, 'Cream' => 1, 'Oil' => 5, 'Serum' => 0], $this->productReportRows(''));
        $this->assertSame(['total_products' => 4, 'total_categories' => 2, 'total_sold_quantity' => 11],
            $this->getJson('/api/admin/products-report/overview')->assertOk()->json('data'));
    }
}
