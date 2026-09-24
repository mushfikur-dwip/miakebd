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
        // The app needs the id to fire its own events.
        $this->assertStringContainsString('__BOOT_PIXEL__', $html);
        $this->assertStringContainsString(self::PIXEL_ID, $html);
    }

    /**
     * What keeps Meta and Google quiet: one init, and no <img> in <head>.
     * Initialising a second time (it used to, to hand over customer details)
     * is reported by Meta as a duplicate pixel; an <img> inside <head> is
     * invalid HTML that ends the head early for non-JavaScript parsers.
     */
    public function test_the_pixel_is_initialised_once_with_nothing_invalid_in_the_head(): void
    {
        config(['services.meta_pixel.id' => self::PIXEL_ID]);

        $html = $this->get('/')->assertOk()->getContent();
        $head = explode('</head>', $html)[0];

        $this->assertSame(1, substr_count($html, "fbq('init'"));
        $this->assertStringNotContainsString('facebook.com/tr', $html, 'no noscript tracking image');
        $this->assertDoesNotMatchRegularExpression('/<noscript>\s*<img/i', $head);
    }

    /** The script waits for the page, so it never slows the first paint. */
    public function test_the_pixel_script_is_fetched_after_the_page_has_loaded(): void
    {
        config(['services.meta_pixel.id' => self::PIXEL_ID]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString("addEventListener('load',l)", $html);
        $this->assertStringContainsString("fbq('track', 'PageView')", $html, 'queued straight away, sent when the script arrives');
    }

    private function cookie($response, string $name): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    /**
     * A visitor from an ad keeps its click id even when the pixel is blocked -
     * in the pixel's own format, readable by the page and not encrypted, or
     * neither the pixel nor the Conversions API could use it.
     */
    public function test_an_ad_click_id_is_kept_as_the_pixels_cookie(): void
    {
        config(['services.meta_pixel.id' => self::PIXEL_ID]);

        $response = $this->get('/?fbclid=IwAR0abcDEF_123-xyz')->assertOk();

        $fbc = $this->cookie($response, '_fbc');
        $this->assertNotNull($fbc);
        $this->assertMatchesRegularExpression('/^fb\.1\.\d{13}\.IwAR0abcDEF_123-xyz$/', $fbc->getValue());
        $this->assertFalse($fbc->isHttpOnly(), 'the pixel has to read it');

        $fbp = $this->cookie($response, '_fbp');
        $this->assertNotNull($fbp);
        $this->assertMatchesRegularExpression('/^fb\.1\.\d{13}\.\d{10}$/', $fbp->getValue());
    }

    public function test_an_existing_browser_id_is_kept(): void
    {
        config(['services.meta_pixel.id' => self::PIXEL_ID]);

        $response = $this->withUnencryptedCookie('_fbp', 'fb.1.1727200000000.1234567890')->get('/')->assertOk();

        $this->assertNull($this->cookie($response, '_fbp'));
    }

    public function test_no_meta_cookies_without_a_pixel(): void
    {
        $response = $this->get('/?fbclid=IwAR0abcDEF_123-xyz')->assertOk();

        $this->assertNull($this->cookie($response, '_fbc'));
        $this->assertNull($this->cookie($response, '_fbp'));
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
     * fire its own events and mirror them to the server.
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
