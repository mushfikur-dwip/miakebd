<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use Dipokhalder\Settings\Facades\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The facts the site states about itself must agree with each other and with
 * the shop: search engines and AI answer engines cross-check them.
 */
class SiteSchemaTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function product(array $attributes = []): Product
    {
        $n = ++$this->sequence;

        return Product::create($attributes + [
            'name'             => 'Product ' . $n,
            'slug'             => 'product-' . $n,
            'sku'              => 'SKU' . $n,
            'status'           => Status::ACTIVE,
            'can_purchasable'  => Ask::YES,
            'buying_price'     => 800,
            'selling_price'    => 1220,
            'variation_price'  => 1220,
            'product_brand_id' => ProductBrand::defaultId(),
        ]);
    }

    private function homepageNode(string $type): array
    {
        $html = $this->get('/')->assertOk()->getContent();
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $scripts);

        foreach ($scripts[1] as $json) {
            foreach (json_decode($json, true)['@graph'] ?? [] as $node) {
                if (in_array($type, (array) $node['@type'], true)) {
                    return $node;
                }
            }
        }

        $this->fail("No {$type} node on the homepage");
    }

    /**
     * sameAs is how Google and AI engines tie the shop's Facebook page and
     * YouTube channel to the same business entity as the website.
     */
    public function test_the_organization_links_its_social_profiles(): void
    {
        Settings::group('social_media')->set([
            'social_media_facebook'  => 'https://www.facebook.com/suglow',
            'social_media_instagram' => null,
            'social_media_twitter'   => '',
            'social_media_youtube'   => 'https://www.youtube.com/@suglow01',
        ]);

        $this->assertSame(
            ['https://www.facebook.com/suglow', 'https://www.youtube.com/@suglow01'],
            $this->homepageNode('Organization')['sameAs'] ?? null
        );
    }

    public function test_no_social_profiles_means_no_same_as(): void
    {
        Settings::group('social_media')->set(['social_media_facebook' => 'not a url']);

        $this->assertArrayNotHasKey('sameAs', $this->homepageNode('Organization'));
    }

    public function test_the_faq_counts_the_products_actually_on_sale(): void
    {
        $this->product();
        $this->product();
        $this->product();
        $this->product(['status' => Status::INACTIVE]);
        $this->product(['pos_only' => Ask::YES]);
        Cache::forget('seo:product-count');

        $faq = collect($this->homepageNode('FAQPage')['mainEntity'])->firstWhere('name', 'What products does Suglow sell?');

        $this->assertStringContainsString('Suglow stocks 3 products across', $faq['acceptedAnswer']['text']);
    }

    /** The placeholder brand used to appear in link previews as "No Brand". */
    public function test_link_previews_name_real_brands_only(): void
    {
        $category = ProductCategory::create(['name' => 'Moisturizer', 'slug' => 'moisturizer', 'status' => Status::ACTIVE]);
        $real     = ProductBrand::create(['name' => 'CeraVe', 'slug' => 'cerave', 'status' => Status::ACTIVE]);

        $unbranded = $this->product(['product_category_id' => $category->id]);
        $branded   = $this->product(['product_category_id' => $category->id, 'product_brand_id' => $real->id]);

        $this->assertStringNotContainsString('property="product:brand"', $this->get('/product/' . $unbranded->slug)->assertOk()->getContent());
        $this->assertStringContainsString('<meta property="product:brand" content="CeraVe">', $this->get('/product/' . $branded->slug)->getContent());
    }
}
