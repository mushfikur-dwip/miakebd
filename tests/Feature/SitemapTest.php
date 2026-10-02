<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * public/sitemap.xml: every page worth indexing, and - for Google Images,
 * where cosmetics are searched visually - each product's real photos.
 */
class SitemapTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://suglow.com']);
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sitemap-' . uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function product(string $slug): Product
    {
        $n = ++$this->sequence;

        return Product::create([
            'name'            => 'Product ' . $n,
            'slug'            => $slug,
            'sku'             => 'SKU' . $n,
            'status'          => Status::ACTIVE,
            'can_purchasable' => Ask::YES,
            'buying_price'    => 800,
            'selling_price'   => 1220,
            'variation_price' => 1220,
        ]);
    }

    /** @return array<string, list<string>> image URLs keyed by page URL */
    private function generate(): array
    {
        $this->artisan('sitemap:generate', ['--path' => $this->dir])->assertExitCode(0);

        $xml = file_get_contents($this->dir . '/sitemap.xml');
        $this->assertStringContainsString('xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"', $xml);

        $urls = [];
        $sitemap = simplexml_load_string($xml);
        foreach ($sitemap->url as $url) {
            $images = [];
            foreach ($url->children('http://www.google.com/schemas/sitemap-image/1.1')->image as $image) {
                $images[] = (string) $image->loc;
            }
            $urls[(string) $url->loc] = $images;
        }

        return $urls;
    }

    public function test_most_popular_is_listed(): void
    {
        $this->assertArrayHasKey('https://suglow.com/most-popular', $this->generate());
    }

    public function test_products_list_their_real_photos_only(): void
    {
        Storage::fake('public');

        $this->product('with-photo')->addMedia(UploadedFile::fake()->image('photo.jpg', 800, 800))->toMediaCollection('product');
        $this->product('without-photo');

        $urls = $this->generate();

        $this->assertCount(1, $urls['https://suglow.com/product/with-photo']);
        $this->assertStringNotContainsString('/images/default/', $urls['https://suglow.com/product/with-photo'][0]);
        $this->assertStringStartsWith('http', $urls['https://suglow.com/product/with-photo'][0]);
        $this->assertSame([], $urls['https://suglow.com/product/without-photo']);
    }
}
