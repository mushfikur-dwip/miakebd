<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The storefront shell carries the shop's settings, so the SPA can draw the
 * header, logo and prices without first waiting for
 * GET /api/frontend/setting - one round trip less on a first visit.
 *
 * The shape of that script tag is what the store reads, and a malformed one
 * would be a syntax error on every page of the shop, so it is pinned here.
 */
class HomeBootDataTest extends TestCase
{
    use RefreshDatabase;

    private function bootPayload(string $html): array
    {
        $this->assertStringContainsString('window.__BOOT_SETTING__ = ', $html, 'the shell no longer carries the settings');

        preg_match('/window\.__BOOT_SETTING__ = (.*?);<\/script>/s', $html, $m);
        $this->assertNotEmpty($m, 'could not read the inlined settings');

        $decoded = json_decode($m[1], true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'the inlined settings are not valid JSON: ' . $m[1]);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function test_the_home_page_carries_the_settings_for_the_spa(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $this->bootPayload($response->getContent());
    }

    /**
     * With no settings configured the resource cannot be built, and that must
     * degrade to an empty object - the store then fetches the endpoint, exactly
     * as it did before - rather than taking the page down.
     */
    public function test_a_shop_with_no_settings_still_renders(): void
    {
        $this->get('/')->assertOk();

        $this->assertSame([], $this->bootPayload($this->get('/')->getContent()));
    }
}
