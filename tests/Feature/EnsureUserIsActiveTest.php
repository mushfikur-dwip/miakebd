<?php

namespace Tests\Feature;

use App\Enums\Status;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

/**
 * This middleware locked the store owner out of their own admin panel, so it
 * gets tests.
 *
 * It answers 401 *and* deletes the caller's token, which makes a wrong verdict
 * expensive: every admin endpoint fails at once, the storefront fails with it,
 * and logging back in does not help because each new token is burned on its
 * first admin request.
 */
class EnsureUserIsActiveTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $status): User
    {
        return User::create([
            'name'     => 'Test Person',
            'username' => 'person' . $status,
            'email'    => 'person' . $status . '@example.test',
            'password' => bcrypt('secret'),
            'status'   => $status,
        ]);
    }

    private function pass(?User $user): bool
    {
        $request = Request::create('/api/admin/stock', 'GET');
        $request->setUserResolver(fn() => $user);

        $response = (new EnsureUserIsActive)->handle(
            $request,
            fn() => new Response('ok', 200)
        );

        return $response->getStatusCode() === 200;
    }

    public function test_an_active_account_is_let_through(): void
    {
        $this->assertTrue($this->pass($this->user(Status::ACTIVE)));
    }

    public function test_a_deactivated_account_is_refused(): void
    {
        $this->assertFalse($this->pass($this->user(Status::INACTIVE)));
    }

    /**
     * The regression this file exists for. A status of 1 - what an older
     * schema or a data import leaves behind - is not a deactivated account,
     * but "!== ACTIVE" judged it as one and took the whole panel down.
     */
    public function test_a_legacy_status_outside_the_enum_is_not_treated_as_deactivated(): void
    {
        $this->assertTrue($this->pass($this->user(1)));
    }

    public function test_a_request_with_no_user_is_left_for_the_auth_middleware(): void
    {
        $this->assertTrue($this->pass(null));
    }
}
