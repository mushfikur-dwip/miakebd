<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OtpType;
use App\Enums\Role as EnumRole;
use App\Enums\Status;
use App\Http\Requests\VerifyPhoneRequest;
use App\Models\User;
use App\Services\OtpManagerService;
use Dipokhalder\Settings\Facades\Settings;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Customer', 'Manager', 'POS Operator', 'Stuff'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'sanctum']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Settings::group('otp')->set(['otp_type' => OtpType::SMS, 'otp_digit_limit' => 4, 'otp_expire_time' => 10]);
        config(['app.api_key' => 'test-key']);

        $this->user = User::create([
            'name' => 'Rima', 'username' => 'rima', 'email' => 'rima@example.com', 'password' => bcrypt('secret123'),
            'phone' => '01711111111', 'country_code' => '+880', 'is_guest' => Ask::NO, 'status' => Status::ACTIVE,
        ]);
        $this->user->assignRole(EnumRole::CUSTOMER);
    }

    private function verify(string $code): bool
    {
        $request = VerifyPhoneRequest::create('/', 'POST', ['phone' => '01711111111', 'country_code' => '+880', 'token' => $code]);

        return app(OtpManagerService::class)->verifyPhone($request);
    }

    /**
     * Route throttles are per IP, so a 4-digit code could be guessed from a
     * few hundred addresses. Five wrong guesses now lock the number itself.
     */
    public function test_five_wrong_codes_lock_the_number_even_against_the_right_code(): void
    {
        DB::table('otps')->insert(['phone' => '01711111111', 'code' => '+880', 'token' => '4821', 'created_at' => now()]);

        for ($i = 0; $i < 5; $i++) {
            try {
                $this->verify('0000');
                $this->fail('a wrong code was accepted');
            } catch (Exception $e) {
                $this->assertSame(trans('all.message.code_is_invalid'), $e->getMessage());
            }
        }

        try {
            $this->verify('4821');
            $this->fail('the number was not locked');
        } catch (Exception $e) {
            $this->assertSame(trans('all.message.otp_too_many_attempts'), $e->getMessage());
        }
    }

    public function test_the_right_code_still_verifies_before_the_limit(): void
    {
        DB::table('otps')->insert(['phone' => '01711111111', 'code' => '+880', 'token' => '4821', 'created_at' => now()]);

        try {
            $this->verify('0000');
        } catch (Exception $e) {
        }

        $this->assertTrue($this->verify('4821'));
    }

    public function test_refreshing_a_token_retires_the_old_one(): void
    {
        $old = $this->user->createToken('auth_token')->plainTextToken;

        $new = $this->postJson('/api/refresh-token', ['token' => $old])->assertCreated()->json('token');

        $this->assertNull(PersonalAccessToken::findToken($old));
        $this->assertNotNull(PersonalAccessToken::findToken($new));
    }

    public function test_an_expired_token_cannot_be_refreshed(): void
    {
        config(['sanctum.expiration' => 60]);
        $old = $this->user->createToken('auth_token')->plainTextToken;

        $this->travel(2)->hours();

        $this->postJson('/api/refresh-token', ['token' => $old])->assertStatus(422);
    }

    public function test_changing_the_password_signs_out_every_other_session(): void
    {
        $current = $this->user->createToken('auth_token')->plainTextToken;
        $other   = $this->user->createToken('auth_token')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $current, 'x-api-key' => 'test-key'])
            ->putJson('/api/profile/change-password', [
                'old_password' => 'secret123', 'new_password' => 'newsecret1', 'confirm_password' => 'newsecret1',
            ])->assertOk();

        $this->assertNotNull(PersonalAccessToken::findToken($current));
        $this->assertNull(PersonalAccessToken::findToken($other));
    }

    public function test_the_auth_routes_refuse_a_missing_or_wrong_api_key(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'rima@example.com', 'password' => 'secret123'])->assertStatus(400);
        $this->withHeaders(['x-api-key' => 'wrong'])
            ->postJson('/api/auth/login', ['email' => 'rima@example.com', 'password' => 'secret123'])->assertStatus(400);
        $this->withHeaders(['x-api-key' => 'test-key'])
            ->postJson('/api/auth/login', ['email' => 'rima@example.com', 'password' => 'secret123'])->assertCreated();
    }

    public function test_a_list_cannot_be_sorted_by_a_credential_column(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/frontend/address?order_column=password')->assertStatus(422);
        $this->getJson('/api/frontend/address?order_column=id')->assertOk();
    }
}
