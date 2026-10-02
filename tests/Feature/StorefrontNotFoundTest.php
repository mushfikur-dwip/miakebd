<?php

namespace Tests\Feature;

use App\Support\StorefrontPaths;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * What the server answers for addresses the shop does not have, and for the
 * SPA's private screens.
 *
 * Before: any unknown URL answered 200 with the SPA shell, "index, follow" and
 * a canonical pointing at itself - a soft 404 Google could index - while a
 * removed product or category answered Laravel's bare "Not Found" page with no
 * header, search or navigation. Checkout and account screens were indexable.
 */
class StorefrontNotFoundTest extends TestCase
{
    use RefreshDatabase;

    private function title(string $html): string
    {
        preg_match('/<title>([^<]*)<\/title>/', $html, $match);

        return html_entity_decode($match[1] ?? '');
    }

    private function assertStorefront404(string $path): void
    {
        $html = $this->get($path)->assertNotFound()->getContent();

        $this->assertStringContainsString('<div id="app"></div>', $html, $path);
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $html, $path);
        $this->assertStringNotContainsString('rel="canonical"', $html, $path);
        $this->assertSame('Page Not Found | Suglow', $this->title($html), $path);
    }

    public function test_an_unknown_address_is_a_real_404_with_the_shop_around_it(): void
    {
        $this->assertStorefront404('/this-page-does-not-exist');
        $this->assertStorefront404('/shop/old-woocommerce-page');
    }

    public function test_missing_products_categories_brands_and_posts_get_the_shop_404(): void
    {
        foreach (['/product/no-such-product', '/product-category/no-such', '/brand/no-such', '/blog/no-such'] as $path) {
            $this->assertStorefront404($path);
        }
    }

    public function test_file_probes_and_missing_uploads_get_the_plain_404(): void
    {
        foreach (['/wp-login.php', '/.git/config.bak', '/storage/nope.png'] as $path) {
            $response = $this->get($path)->assertNotFound();
            $this->assertStringNotContainsString('<div id="app">', $response->getContent(), $path);
        }
    }

    public function test_api_404s_stay_json(): void
    {
        $this->getJson('/api/frontend/nope')->assertNotFound()->assertJsonPath('success', false);
    }

    public function test_private_screens_load_but_are_not_indexed(): void
    {
        foreach (['/account/order-history', '/checkout/cart-list', '/signup', '/wishlist', '/admin/dashboard'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            $this->assertStringContainsString('<div id="app"></div>', $html, $path);
            $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $html, $path);
        }
    }

    /**
     * The SPA used to rewrite / to /home, so every link a customer copied was
     * a second homepage URL. The homepage is / now; /home is only an alias.
     */
    public function test_home_alias_redirects_permanently(): void
    {
        $this->get('/home')->assertStatus(301)->assertRedirect('/');
        $this->get('/home?utm_source=facebook')->assertStatus(301)->assertRedirect('/?utm_source=facebook');
    }

    public function test_the_homepage_is_still_indexed_and_no_page_overrides_robots_for_google(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="robots" content="index, follow', $html);
        $this->assertStringNotContainsString('name="googlebot"', $html);
        $this->assertStringNotContainsString('name="googlebot"', $this->get('/signup')->getContent());
    }

    /**
     * Every path the Vue router can render must be known to the server, or a
     * real page would be answered with a 404 status (it still renders, but
     * Google and link previews would drop it).
     */
    public function test_every_spa_route_is_known_to_the_server(): void
    {
        $paths = [];
        foreach (glob(resource_path('js/router/modules/*.js')) as $file) {
            $parent = '';
            preg_match_all('/path:\s*["\']([^"\']*)["\']/', file_get_contents($file), $matches);
            foreach ($matches[1] as $path) {
                if (str_starts_with($path, '/')) {
                    $parent = rtrim($path, '/');
                    $paths[] = $path;
                } else {
                    $paths[] = $parent . '/' . $path;
                }
            }
        }
        $this->assertContains('/account/order-history', $paths, 'route files were not parsed');

        foreach (array_unique($paths) as $path) {
            $concrete = preg_replace('/:[A-Za-z]+/', 'x', $path);
            $route = Route::getRoutes()->match(Request::create($concrete, 'GET'));

            if (!$route->isFallback) {
                continue; // an explicit web route owns it
            }

            $this->assertNotSame(StorefrontPaths::MISSING, StorefrontPaths::classify(ltrim($concrete, '/')), $path);
        }
    }
}
