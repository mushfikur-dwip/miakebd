<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The storefront and admin never use the camera, microphone or location.
 * Saying so in Permissions-Policy means injected or third-party script (an
 * analytics snippet, a compromised dependency) cannot prompt shoppers for them
 * on suglow.com's name.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_and_api_deny_camera_microphone_and_location(): void
    {
        foreach (['/', '/api/frontend/setting'] as $path) {
            $this->get($path)
                ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        }
    }
}
