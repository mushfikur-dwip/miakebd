<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Status;
use App\Enums\SwitchBox;
use App\Events\SendPosCustomerSms;
use App\Http\Requests\CustomerRequest;
use App\Http\Requests\PosCustomerRequest;
use App\Services\CustomerService;
use App\Models\NotificationAlert;
use App\Models\User;
use App\Services\PosCustomerSmsNotificationBuilder;
use App\Services\SmsManagerService;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The SMS a customer gets when the till saves their details. It must reach the
 * number the cashier typed once the shop switches it on, and nobody otherwise.
 */
class PosCustomerSmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function switchAlert(int $state): void
    {
        NotificationAlert::where('language', PosCustomerSmsNotificationBuilder::ALERT)->update([
            'sms'         => $state,
            'sms_message' => 'Dear {name}, welcome to our shop.',
        ]);
    }

    /** Stands in for the configured gateway, so no real SMS can leave a test. */
    private function gateway(callable $expectations, bool $enabled = true): void
    {
        $this->mock(SmsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('gateway')->andReturn('bulksmsbd');
        });
        $this->mock(SmsManagerService::class, function (MockInterface $mock) use ($expectations, $enabled) {
            $mock->shouldReceive('gateway')->with('bulksmsbd')->andReturnSelf();
            $mock->shouldReceive('status')->andReturn($enabled);
            $expectations($mock);
        });
    }

    /**
     * Note for anyone adding tests here: the HTTP helper terminates the
     * application itself, so the SMS has already been attempted by the time
     * this returns. Calling terminate() again would send it a second time -
     * terminate() does not clear its callbacks - which a real request never
     * does, because a request is terminated once.
     */
    private function addCustomer(array $form = [])
    {
        return $this->postJson('/api/admin/pos/customer', $form + [
            'name'         => 'Esteak',
            'phone'        => '1712345678',
            'country_code' => '+880',
            'status'       => Status::ACTIVE,
        ]);
    }

    public function test_the_message_ships_switched_off(): void
    {
        $alert = NotificationAlert::where('language', PosCustomerSmsNotificationBuilder::ALERT)->first();

        $this->assertNotNull($alert, 'the row Settings > Notification Alert lists');
        $this->assertSame((int) SwitchBox::OFF, (int) $alert->sms);
    }

    public function test_the_number_the_cashier_typed_gets_the_message(): void
    {
        $this->switchAlert(SwitchBox::ON);
        $this->gateway(function (MockInterface $mock) {
            $mock->shouldReceive('send')->once()->with('+880', '1712345678', 'Dear Esteak, welcome to our shop.');
        });

        $this->addCustomer()->assertSuccessful();
    }

    public function test_nothing_is_sent_while_the_shop_has_it_switched_off(): void
    {
        $this->switchAlert(SwitchBox::OFF);
        $this->gateway(fn(MockInterface $mock) => $mock->shouldNotReceive('send'));

        $this->addCustomer()->assertSuccessful();
    }

    public function test_nothing_is_sent_while_no_gateway_is_enabled(): void
    {
        $this->switchAlert(SwitchBox::ON);
        $this->gateway(fn(MockInterface $mock) => $mock->shouldNotReceive('send'), false);

        $this->addCustomer()->assertSuccessful();
    }

    /**
     * The gateway can take up to 30s on a bad connection and the queue runs
     * sync, so the cashier must have the saved customer back first.
     */
    public function test_the_cashier_does_not_wait_for_the_sms(): void
    {
        Event::fake([SendPosCustomerSms::class]);

        // Straight to the service, because the HTTP helper terminates the
        // application for us and there would be no "before" left to assert.
        $request = PosCustomerRequest::create('/api/admin/pos/customer', 'POST', [
            'name'         => 'Esteak',
            'phone'        => '1712345678',
            'country_code' => '+880',
            'status'       => Status::ACTIVE,
        ]);
        $request->setContainer(app())->validateResolved();

        $customer = app(CustomerService::class)->storePosCustomer($request);

        Event::assertNotDispatched(SendPosCustomerSms::class);

        $this->app->terminate();

        Event::assertDispatched(SendPosCustomerSms::class, fn($event) => $event->info['user_id'] === $customer->id);
    }

    /** Only the till's form texts; the admin Customers page still does not. */
    public function test_a_customer_added_on_the_admin_page_is_not_texted(): void
    {
        $this->switchAlert(SwitchBox::ON);
        $this->gateway(fn(MockInterface $mock) => $mock->shouldNotReceive('send'));

        $request = CustomerRequest::create('/api/admin/customer', 'POST', [
            'name'                  => 'Added In Admin',
            'email'                 => 'adminadded@example.test',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
            'phone'                 => '1799999999',
            'country_code'          => '+880',
            'status'                => Status::ACTIVE,
        ]);
        $request->setContainer(app())->validateResolved();

        $customer = app(CustomerService::class)->store($request);

        $this->app->terminate();

        // A real account, unlike the till's guest record - and no SMS.
        $this->assertSame(Ask::NO, (int) User::findOrFail($customer->id)->is_guest);
    }
}
