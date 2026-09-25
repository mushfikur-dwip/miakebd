<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Role as EnumRole;
use App\Enums\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The customer picker at the till.
 *
 * It searches whatever it is shown and nothing else, so a customer could only
 * be found by name - useless at a counter, where the phone number is usually
 * all anyone has. The number now travels with the name.
 *
 * Phones are stored without their leading zero (guest checkout strips it, the
 * calling code lives in country_code), so the local form is rebuilt here: that
 * is the form a customer reads out and a cashier types.
 */
class PosCustomerSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Customer', 'Manager', 'POS Operator', 'Stuff'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'sanctum']);
        }
        Permission::findOrCreate('customers', 'sanctum');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function customer(string $name, ?string $phone, string $code = '+880'): User
    {
        $slug = strtolower(str_replace(' ', '', $name));

        $user = User::create([
            'name' => $name, 'username' => $slug . random_int(1, 99999), 'email' => $slug . '@example.com',
            'password' => bcrypt('secret123'), 'phone' => $phone, 'country_code' => $code,
            'is_guest' => Ask::NO, 'status' => Status::ACTIVE,
        ]);
        $user->assignRole(EnumRole::CUSTOMER);

        return $user;
    }

    private function actAsCashier(): void
    {
        $cashier = User::create([
            'name' => 'Cashier', 'username' => 'cashier', 'email' => 'cashier@example.com',
            'password' => bcrypt('secret123'), 'phone' => '1999999999', 'country_code' => '+880',
            'is_guest' => Ask::NO, 'status' => Status::ACTIVE,
        ]);
        $cashier->assignRole(EnumRole::POS_OPERATOR);
        $cashier->givePermissionTo('customers');

        Sanctum::actingAs($cashier);
    }

    public function test_the_picker_receives_the_phone_number(): void
    {
        $this->customer('Rima Akter', '1711111111');
        $this->actAsCashier();

        $row = collect($this->getJson('/api/admin/users?role_id=' . EnumRole::CUSTOMER)->assertOk()->json('data'))
            ->firstWhere('name', 'Rima Akter');

        $this->assertSame('1711111111', $row['phone']);
        // What the cashier actually types.
        $this->assertSame('01711111111', $row['local_phone']);
    }

    public function test_a_number_already_stored_with_its_zero_is_left_alone(): void
    {
        $this->customer('Karim Mia', '01822222222');
        $this->actAsCashier();

        $row = collect($this->getJson('/api/admin/users?role_id=' . EnumRole::CUSTOMER)->assertOk()->json('data'))
            ->firstWhere('name', 'Karim Mia');

        $this->assertSame('01822222222', $row['local_phone'], 'the zero must not be doubled');
    }

    public function test_a_customer_with_no_number_still_comes_back(): void
    {
        $this->customer('No Phone', null);
        $this->actAsCashier();

        $row = collect($this->getJson('/api/admin/users?role_id=' . EnumRole::CUSTOMER)->assertOk()->json('data'))
            ->firstWhere('name', 'No Phone');

        $this->assertNotNull($row, 'a customer without a phone must not disappear from the till');
        $this->assertSame('', $row['local_phone']);
    }

    /** The list is customer data; it stays behind the same permission as before. */
    public function test_the_list_still_needs_the_customers_permission(): void
    {
        $shopper = $this->customer('Shopper', '1733333333');
        Sanctum::actingAs($shopper);

        $this->getJson('/api/admin/users?role_id=' . EnumRole::CUSTOMER)->assertForbidden();
    }
}
