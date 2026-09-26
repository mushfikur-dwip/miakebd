<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Status;
use App\Http\Middleware\CachePublicResponse;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Shared, short-lived copies of the public catalogue responses, so an ad burst
 * reads from the cache instead of opening a MySQL connection per request.
 */
class PublicResponseCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);

        ProductCategory::create(['name' => 'Skin Care', 'slug' => 'skin-care', 'status' => Status::ACTIVE]);
    }

    private function categories(array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($headers)->getJson('/api/frontend/product-category?paginate=0&status=5');
    }

    public function test_a_second_anonymous_request_is_served_without_the_database(): void
    {
        $this->categories()->assertOk()->assertHeader('X-Cache', 'MISS');

        DB::enableQueryLog();
        $second = $this->categories()->assertOk()->assertHeader('X-Cache', 'HIT');

        $this->assertCount(0, DB::getQueryLog());
        $this->assertStringContainsString('Skin Care', $second->getContent());
    }

    public function test_an_admin_change_is_visible_on_the_next_request(): void
    {
        $this->categories()->assertHeader('X-Cache', 'MISS');
        ProductCategory::query()->update(['name' => 'Face Care']);

        $this->categories()->assertHeader('X-Cache', 'HIT');

        CachePublicResponse::flush();

        $fresh = $this->categories()->assertHeader('X-Cache', 'MISS');
        $this->assertStringContainsString('Face Care', $fresh->getContent());
    }

    public function test_every_admin_route_clears_the_cache_on_writes(): void
    {
        $admin = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/admin/'));

        $this->assertNotEmpty($admin);
        foreach ($admin as $route) {
            $this->assertContains('flush.public', $route->gatherMiddleware(), $route->uri());
        }
    }

    public function test_a_signed_in_customer_is_never_served_a_shared_copy(): void
    {
        $user = User::create([
            'name' => 'Rima', 'username' => 'rima', 'phone' => '1711111111', 'country_code' => '+880',
            'is_guest' => Ask::NO, 'status' => Status::ACTIVE, 'password' => bcrypt('x'),
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->categories();
        $response = $this->categories(['Authorization' => 'Bearer ' . $token])->assertOk();

        $this->assertFalse($response->headers->has('X-Cache'));
    }

    public function test_personal_endpoints_are_not_cached(): void
    {
        $this->getJson('/api/frontend/cookies')->assertOk();
        $response = $this->getJson('/api/frontend/cookies');

        $this->assertFalse($response->headers->has('X-Cache'));
    }
}
