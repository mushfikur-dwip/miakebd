<?php

namespace Tests\Feature;

use App\Enums\AnalyticSection;
use App\Enums\Status;
use App\Models\Analytic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Meta Pixel on the storefront shell.
 *
 * The rule that matters is the third test: the pixel must be initialised once
 * per page. A shop that has already pasted Meta's snippet into
 * Admin -> Analytics and then configures the id here would otherwise fire two
 * PageViews for every visit - doubling reach, halving the apparent cost per
 * result, and quietly making every ad decision on bad numbers.
 */
class MetaPixelTest extends TestCase
{
    use RefreshDatabase;

    private const PIXEL_ID = '1234567890123456';

    private function pasteSnippetInAnalytics(string $pixelId): void
    {
        $analytic = Analytic::create(['name' => 'Meta Pixel', 'status' => Status::ACTIVE]);
        $analytic->analyticSections()->create([
            'name'    => 'head',
            'section' => AnalyticSection::HEAD,
            // Meta's own snippet, as Events Manager hands it over.
            'data'    => "<script>!function(f,b,e,v,n,t,s){}(window,document,'script',"
                . "'https://connect.facebook.net/en_US/fbevents.js');"
                . "fbq('init', '{$pixelId}');fbq('track', 'PageView');</script>",
        ]);
    }

    public function test_no_pixel_is_rendered_when_none_is_configured(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('connect.facebook.net', $html);
        $this->assertStringNotContainsString('__BOOT_PIXEL__', $html);
    }

    public function test_the_configured_pixel_loads_and_reaches_the_app(): void
    {
        config(['services.meta_pixel.id' => self::PIXEL_ID]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('connect.facebook.net/en_US/fbevents.js', $html);
        $this->assertStringContainsString("fbq('init', \"" . self::PIXEL_ID . "\")", $html);
        // The app needs the id for Advanced Matching.
        $this->assertStringContainsString('__BOOT_PIXEL__', $html);
        $this->assertStringContainsString(self::PIXEL_ID, $html);
    }

    public function test_the_base_code_is_not_printed_twice_when_analytics_already_has_it(): void
    {
        config(['services.meta_pixel.id' => self::PIXEL_ID]);
        $this->pasteSnippetInAnalytics(self::PIXEL_ID);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, "fbq('init'"), 'the pixel is initialised more than once');
        $this->assertSame(1, substr_count($html, 'connect.facebook.net/en_US/fbevents.js'));
    }

    /**
     * With only the pasted snippet, the app still has to know the id so it can
     * hand over the customer's details for Advanced Matching.
     */
    public function test_the_id_is_read_out_of_a_pasted_snippet(): void
    {
        $this->pasteSnippetInAnalytics(self::PIXEL_ID);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('__BOOT_PIXEL__', $html);
        $this->assertStringContainsString('"id":"' . self::PIXEL_ID . '"', $html);
        $this->assertSame(1, substr_count($html, 'connect.facebook.net/en_US/fbevents.js'));
    }

    public function test_a_junk_pixel_id_is_ignored(): void
    {
        config(['services.meta_pixel.id' => 'not-a-pixel-id']);

        $this->assertStringNotContainsString('connect.facebook.net', $this->get('/')->assertOk()->getContent());
    }
}
