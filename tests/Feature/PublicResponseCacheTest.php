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

    private function signedIn(): array
    {
        $user = User::create([
            'name' => 'Rima', 'username' => 'rima', 'phone' => '1711111111', 'country_code' => '+880',
            'is_guest' => Ask::NO, 'status' => Status::ACTIVE, 'password' => bcrypt('x'),
        ]);

        return ['Authorization' => 'Bearer ' . $user->createToken('auth_token')->plainTextToken];
    }

    /** Product lists carry the viewer's own wishlist hearts. */
    public function test_a_signed_in_customer_is_never_served_a_shared_product_list(): void
    {
        $headers = $this->signedIn();

        $this->getJson('/api/frontend/product?paginate=0')->assertOk()->assertHeader('X-Cache', 'MISS');
        $response = $this->withHeaders($headers)->getJson('/api/frontend/product?paginate=0')->assertOk();

        $this->assertFalse($response->headers->has('X-Cache'));
    }

    /**
     * Menus, categories, languages and the like are the same for everyone, so
     * a signed-in visitor reads the shared copy too. Before, each of their
     * page loads opened some twenty MySQL connections at once - enough on its
     * own to reach the host's limit and fail with "A database error occurred".
     */
    public function test_a_signed_in_customer_shares_what_is_the_same_for_everyone(): void
    {
        $headers = $this->signedIn();

        $this->categories()->assertHeader('X-Cache', 'MISS');

        DB::enableQueryLog();
        $this->categories($headers)->assertOk()->assertHeader('X-Cache', 'HIT');
        $this->assertCount(0, DB::getQueryLog());
    }

    /** Anything that embeds products must never be marked shared. */
    public function test_nothing_that_embeds_products_is_shared(): void
    {
        $shared = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_ends_with($m, ',shared')))
            ->map(fn ($route) => $route->uri());

        $this->assertContains('api/frontend/language', $shared->all());
        foreach ($shared as $uri) {
            $this->assertDoesNotMatchRegularExpression('#frontend/(product|product-section|promotion|campaign|wishlist)(/|$)#', $uri, $uri);
        }
    }

    public function test_personal_endpoints_are_not_cached(): void
    {
        $this->getJson('/api/frontend/cookies')->assertOk();
        $response = $this->getJson('/api/frontend/cookies');

        $this->assertFalse($response->headers->has('X-Cache'));
    }
}
