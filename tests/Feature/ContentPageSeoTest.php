<?php

namespace Tests\Feature;

use App\Enums\Status;
use App\Models\Campaign;
use App\Models\MenuSection;
use App\Models\Page;
use App\Models\ProductSection;
use App\Models\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CMS pages (About us, Support, Legal) and the promotion / section / campaign
 * landing pages used to reach crawlers with the generic site title and
 * description - the trust pages AI answer engines quote looked like every other
 * page. Each now renders its own metadata, and a missing or switched-off record
 * is a real 404.
 */
class ContentPageSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://suglow.com']);
    }

    private function page(string $title, string $slug, string $body, int $status = Status::ACTIVE): Page
    {
        return Page::create([
            'title'           => $title,
            'slug'            => $slug,
            'description'     => $body,
            'menu_section_id' => MenuSection::create(['name' => 'Footer'])->id,
            'status'          => $status,
        ]);
    }

    private function title(string $html): string
    {
        preg_match('/<title>([^<]*)<\/title>/', $html, $match);

        return html_entity_decode($match[1] ?? '');
    }

    private function meta(string $html, string $name): ?string
    {
        preg_match('/<meta name="' . preg_quote($name, '/') . '" content="([^"]*)">/', $html, $match);

        return isset($match[1]) ? html_entity_decode($match[1], ENT_QUOTES) : null;
    }

    private function types(string $html): array
    {
        preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $match);

        return array_column(json_decode($match[1], true)['@graph'] ?? [], '@type');
    }

    private function noscript(string $html): string
    {
        preg_match('/<noscript>(.*?)<\/noscript>/s', explode('<body>', $html)[1] ?? '', $match);

        return $match[1] ?? '';
    }

    public function test_an_about_page_describes_itself(): void
    {
        $this->page('About Us', 'about-us', '<p>Suglow imports from   Malaysia.</p>');

        $html = $this->get('/page/about-us')->assertOk()->getContent();

        $this->assertSame('About Us | Suglow', $this->title($html));
        $this->assertSame('Suglow imports from Malaysia.', $this->meta($html, 'description'));
        $this->assertStringContainsString('<link rel="canonical" href="https://suglow.com/page/about-us">', $html);
        $this->assertContains('AboutPage', $this->types($html));
        $this->assertContains('BreadcrumbList', $this->types($html));
        $this->assertStringContainsString('<p>Suglow imports from   Malaysia.</p>', $this->noscript($html));
    }

    public function test_a_support_page_is_a_contact_page(): void
    {
        $this->page('Support', 'support', '<p>Call us any time.</p>');

        $this->assertContains('ContactPage', $this->types($this->get('/page/support')->getContent()));
    }

    public function test_a_long_body_gives_a_short_description_cut_at_a_word(): void
    {
        $this->page('Legal', 'legal', '<h2>Terms</h2><p>' . str_repeat('Orders are confirmed by phone before dispatch. ', 10) . '</p>');

        $description = $this->meta($this->get('/page/legal')->getContent(), 'description');

        $this->assertLessThanOrEqual(155, mb_strlen($description));
        $this->assertStringStartsWith('Terms Orders are confirmed by phone before dispatch.', $description);
        $this->assertMatchesRegularExpression('/(dispatch\.|[a-z]…)$/', $description);
    }

    public function test_a_page_without_text_still_gets_a_description(): void
    {
        $this->page('Gallery', 'gallery', '<p><img src="x.jpg"></p>');

        $this->assertSame(
            'Gallery — Suglow, authentic cosmetics and skincare in Bangladesh.',
            $this->meta($this->get('/page/gallery')->getContent(), 'description')
        );
    }

    public function test_switched_off_and_unknown_pages_are_404(): void
    {
        $this->page('Old Terms', 'old-terms', '<p>Old.</p>', Status::INACTIVE);

        $this->get('/page/old-terms')->assertNotFound();
        $this->get('/page/no-such-page')->assertNotFound();
    }

    public function test_promotion_pages(): void
    {
        Promotion::create(['name' => 'Eid Glow Deals', 'slug' => 'eid-glow-deals', 'status' => Status::ACTIVE]);
        Promotion::create(['name' => 'Old Deals', 'slug' => 'old-deals', 'status' => Status::INACTIVE]);

        $this->assertSame(
            'Eid Glow Deals — Offers on Authentic Cosmetics | Suglow',
            $this->title($this->get('/promotion/eid-glow-deals')->assertOk()->getContent())
        );
        $this->get('/promotion/old-deals')->assertNotFound();
        $this->get('/promotion/no-such')->assertNotFound();
    }

    public function test_product_section_pages(): void
    {
        ProductSection::create(['name' => 'Best Sunscreens', 'slug' => 'best-sunscreens', 'status' => Status::ACTIVE]);
        ProductSection::create(['name' => 'Retired', 'slug' => 'retired', 'status' => Status::INACTIVE]);

        $html = $this->get('/product-section/best-sunscreens')->assertOk()->getContent();
        $this->assertSame('Best Sunscreens — Offers on Authentic Cosmetics | Suglow', $this->title($html));
        $this->assertSame(
            'Shop Best Sunscreens at Suglow: authentic skincare and cosmetics with cash on delivery across Bangladesh.',
            $this->meta($html, 'description')
        );
        $this->get('/product-section/retired')->assertNotFound();
    }

    public function test_only_running_campaigns_have_pages(): void
    {
        Campaign::create(['name' => '11.11 Sale', 'slug' => '11-11-sale', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'status' => Status::ACTIVE]);
        Campaign::create(['name' => 'Puja Sale', 'slug' => 'puja-sale', 'starts_at' => now()->subDays(10), 'ends_at' => now()->subDay(), 'status' => Status::ACTIVE]);

        $this->assertSame(
            '11.11 Sale — Offers on Authentic Cosmetics | Suglow',
            $this->title($this->get('/campaign/11-11-sale')->assertOk()->getContent())
        );
        $this->get('/campaign/puja-sale')->assertNotFound();
    }

    public function test_flash_sale_page_has_its_own_title(): void
    {
        $this->assertSame(
            'Flash Sale — Limited-Time Deals on Authentic Cosmetics | Suglow',
            $this->title($this->get('/flash-sale')->assertOk()->getContent())
        );
    }
}
