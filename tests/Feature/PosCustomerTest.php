<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Role as EnumRole;
use App\Enums\Status;
use App\Http\Requests\CustomerRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The till's "add customer" form: a name and a phone are enough, email is
 * optional, there is no password, and the result is a customer record - not
 * an account anyone can log in to.
 */
class PosCustomerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // assignRole(EnumRole::CUSTOMER) looks the role up by id, and the ids
        // are fixed by App\Enums\Role - so create them in that order.
        foreach (['Admin', 'Customer', 'Manager', 'POS Operator', 'Stuff'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'sanctum']);
        }
        Permission::findOrCreate('pos', 'sanctum');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $cashier = User::create([
            'name'     => 'Cashier',
            'username' => 'cashier',
            'email'    => 'cashier@example.test',
            'password' => bcrypt('secret'),
            'status'   => Status::ACTIVE,
        ]);
        $cashier->givePermissionTo('pos');

        Sanctum::actingAs($cashier);
    }

    private function add(array $form)
    {
        return $this->postJson('/api/admin/pos/customer', $form + ['country_code' => '+880', 'status' => Status::ACTIVE]);
    }

    public function test_a_name_and_phone_are_enough(): void
    {
        $this->add(['name' => 'Esteak', 'phone' => '1712345678'])->assertSuccessful();

        $customer = User::where('phone', '1712345678')->firstOrFail();
        $this->assertSame('Esteak', $customer->name);
        $this->assertNull($customer->email);
        $this->assertTrue($customer->hasRole(EnumRole::CUSTOMER), 'listed in the POS customer picker');
    }

    public function test_the_customer_is_a_record_not_an_account(): void
    {
        $this->add(['name' => 'Esteak', 'phone' => '1712345678'])->assertSuccessful();

        $customer = User::where('phone', '1712345678')->firstOrFail();
        $this->assertSame(Ask::YES, (int) $customer->is_guest);
        // LoginController runs every login through this scope.
        $this->assertFalse(User::notGuest()->whereKey($customer->id)->exists());
    }

    public function test_phone_is_required_and_email_and_password_are_not(): void
    {
        $this->add(['name' => 'Esteak'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone')
            ->assertJsonMissingValidationErrors(['email', 'password', 'password_confirmation']);
    }

    public function test_an_email_if_given_must_be_valid_and_not_already_used(): void
    {
        $this->add(['name' => 'Esteak', 'phone' => '1712345678', 'email' => 'not-an-email'])->assertJsonValidationErrors('email');
        $this->add(['name' => 'Esteak', 'phone' => '1712345678', 'email' => 'cashier@example.test'])->assertJsonValidationErrors('email');
        $this->add(['name' => 'Esteak', 'phone' => '1712345678', 'email' => 'esteak@example.test'])->assertSuccessful();
    }

    public function test_a_phone_already_on_file_is_refused_rather_than_duplicated(): void
    {
        $this->add(['name' => 'Esteak', 'phone' => '1712345678'])->assertSuccessful();
        $this->add(['name' => 'Someone Else', 'phone' => '1712345678'])->assertJsonValidationErrors('phone');

        $this->assertSame(1, User::where('phone', '1712345678')->count());
    }

    public function test_the_admin_customers_page_still_creates_full_accounts(): void
    {
        $rules = (new CustomerRequest())->rules();

        $this->assertContains('required', $rules['email']);
        $this->assertContains('required', $rules['password']);
    }
}
