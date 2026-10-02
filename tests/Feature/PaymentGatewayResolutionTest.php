<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Status;
use App\Http\PaymentGateways\Gateways\Cashondelivery;
use App\Models\User;
use App\Services\PaymentManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Payment gateway classes are built from a name that arrives in a URL or a
 * form. Only real gateway classes may come out of that; anything else is a
 * clean refusal, not a "class not found" 500.
 *
 * Also pins the removal of routes that could only ever fail: the API checkout
 * callbacks (which passed the raw URL string as the order) and two frontend
 * routes pointing at controller methods that do not exist.
 */
class PaymentGatewayResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_gateway_names_are_refused(): void
    {
        foreach (['Nonexistent', '..\\Models\\User', 'Models\\User', ''] as $name) {
            try {
                (new PaymentManagerService())->gateway($name);
                $this->fail('Accepted gateway name ' . json_encode($name));
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Unknown payment gateway.', $e->getMessage());
            }
        }
    }

    public function test_a_real_gateway_name_resolves_to_its_class(): void
    {
        $manager = (new PaymentManagerService())->gateway('cashondelivery');

        $this->assertInstanceOf(Cashondelivery::class, $manager->gateway);
    }

    public function test_api_checkout_callbacks_are_gone(): void
    {
        Sanctum::actingAs(User::create([
            'name' => 'Rima', 'username' => 'rima', 'email' => 'rima@example.com', 'password' => bcrypt('secret123'),
            'phone' => '01711111111', 'country_code' => '+880', 'is_guest' => Ask::NO, 'status' => Status::ACTIVE,
        ]));

        foreach (['payment', 'success', 'fail', 'cancel'] as $step) {
            $this->getJson("/api/checkout/1/cashondelivery/{$step}")->assertNotFound();
        }
    }

    public function test_frontend_routes_without_a_method_are_gone(): void
    {
        $this->getJson('/api/frontend/language/en')->assertNotFound();
        $this->getJson('/api/frontend/overview')->assertNotFound();
        // The signed-in overview figures are a separate group and stay.
        $this->getJson('/api/frontend/overview/total-orders')->assertUnauthorized();
    }
}
