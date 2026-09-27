<?php

namespace Tests\Feature;

use App\Enums\AnalyticSection;
use App\Enums\Status;
use App\Models\Analytic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The TikTok Pixel on the storefront shell - the same rules as the Meta Pixel:
 * loaded once per page, never twice, and never while the page is still being
 * drawn.
 */
class TikTokPixelTest extends TestCase
{
    use RefreshDatabase;

    private const PIXEL_ID = 'DAS4C9RC77U88MSO82I0';

    private function pasteSnippetInAnalytics(string $pixelId): void
    {
        $analytic = Analytic::create(['name' => 'TikTok Pixel', 'status' => Status::ACTIVE]);
        $analytic->analyticSections()->create([
            'name'    => 'head',
            'section' => AnalyticSection::HEAD,
            // The tail of TikTok's own snippet, as Events Manager hands it over.
            'data'    => "<script>!function (w, d, t) {var r=\"https://analytics.tiktok.com/i18n/pixel/events.js\";"
                . "ttq.load('{$pixelId}');ttq.page();}(window, document, 'ttq');</script>",
        ]);
    }

    public function test_no_pixel_is_rendered_when_none_is_configured(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('analytics.tiktok.com', $html);
        $this->assertStringNotContainsString('ttq.load', $html);
    }

    public function test_the_configured_pixel_loads_once(): void
    {
        config(['services.tiktok_pixel.id' => self::PIXEL_ID]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('analytics.tiktok.com/i18n/pixel/events.js', $html);
        $this->assertSame(1, substr_count($html, 'ttq.load("' . self::PIXEL_ID . '")'));
        $this->assertSame(1, substr_count($html, 'ttq.page()'));
    }

    /** The script waits for the page, so it never slows the first paint. */
    public function test_the_pixel_script_is_fetched_after_the_page_has_loaded(): void
    {
        config(['services.tiktok_pixel.id' => self::PIXEL_ID]);

        $html = $this->get('/')->assertOk()->getContent();
        $block = substr($html, strpos($html, 'TiktokAnalyticsObject'));

        $this->assertStringContainsString("addEventListener('load',l)", $block);
    }

    public function test_the_base_code_is_not_printed_twice_when_analytics_already_has_it(): void
    {
        config(['services.tiktok_pixel.id' => self::PIXEL_ID]);
        $this->pasteSnippetInAnalytics(self::PIXEL_ID);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'ttq.load('), 'the pixel is loaded more than once');
        $this->assertSame(1, substr_count($html, 'ttq.page()'));
    }

    public function test_it_sits_beside_the_meta_pixel_without_touching_it(): void
    {
        config([
            'services.tiktok_pixel.id' => self::PIXEL_ID,
            'services.meta_pixel.id'   => '1234567890123456',
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, "fbq('init'"));
        $this->assertSame(1, substr_count($html, 'ttq.load('));
    }

    public function test_a_junk_pixel_id_is_ignored(): void
    {
        config(['services.tiktok_pixel.id' => 'not a pixel id"></script>']);

        $this->assertStringNotContainsString('analytics.tiktok.com', $this->get('/')->assertOk()->getContent());
    }
}
