<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Product;
use App\Support\IndexNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * IndexNow tells Bing - which feeds ChatGPT Search, Copilot, DuckDuckGo and
 * Yahoo - plus Yandex, Seznam and Naver about new and changed pages within the
 * hour, instead of whenever their crawlers next come round.
 */
class IndexNowTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.indexnow.enabled' => true, 'app.url' => 'https://suglow.com']);
        Cache::forget(IndexNow::LAST_RUN_KEY);
    }

    /** Registered per test: a second Http::fake() would sit behind the first. */
    private function engineAnswers(int $status): void
    {
        Http::fake(['api.indexnow.org/*' => Http::response('', $status)]);
    }

    private function product(string $slug, array $attributes = []): Product
    {
        $n = ++$this->sequence;

        return Product::create($attributes + [
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

    /** @return list<array> payloads posted to IndexNow */
    private function submissions(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0]->data())->values()->all();
    }

    public function test_the_key_file_is_served_at_the_site_root(): void
    {
        $this->get('/' . IndexNow::key() . '.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertContent(IndexNow::key());

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', IndexNow::key());
        $this->get('/0123456789abcdef0123456789abcdef.txt')->assertNotFound();
    }

    public function test_the_first_run_submits_every_live_page_and_nothing_else(): void
    {
        $this->engineAnswers(200);
        $this->product('kept');
        $this->product('switched-off', ['status' => Status::INACTIVE]);
        $this->product('till-only', ['pos_only' => Ask::YES]);

        $this->artisan('indexnow:submit', ['--all' => true])->assertExitCode(0);

        $sent = $this->submissions();
        $this->assertCount(1, $sent);
        $this->assertSame('suglow.com', $sent[0]['host']);
        $this->assertSame(IndexNow::key(), $sent[0]['key']);
        $this->assertSame('https://suglow.com/' . IndexNow::key() . '.txt', $sent[0]['keyLocation']);
        $this->assertContains('https://suglow.com/product/kept', $sent[0]['urlList']);
        $this->assertContains('https://suglow.com/', $sent[0]['urlList']);
        $this->assertNotContains('https://suglow.com/product/switched-off', $sent[0]['urlList']);
        $this->assertNotContains('https://suglow.com/product/till-only', $sent[0]['urlList']);
    }

    public function test_later_runs_send_only_what_changed(): void
    {
        $this->engineAnswers(202);
        $kept  = $this->product('kept');
        $other = $this->product('other');

        $this->travel(1)->minutes();
        $this->artisan('indexnow:submit')->assertExitCode(0);
        $this->assertCount(1, $this->submissions());

        // Nothing changed since: nothing is sent.
        $this->travel(1)->minutes();
        $this->artisan('indexnow:submit')->expectsOutput('Nothing changed.')->assertExitCode(0);
        $this->assertCount(1, $this->submissions());

        // One product edited, another taken off the website: both are news.
        $kept->touch();
        $other->update(['status' => Status::INACTIVE]);
        $this->travel(1)->minutes();
        $this->artisan('indexnow:submit')->assertExitCode(0);

        $sent = $this->submissions();
        $this->assertCount(2, $sent);
        $this->assertEqualsCanonicalizing(
            ['https://suglow.com/product/kept', 'https://suglow.com/product/other'],
            $sent[1]['urlList']
        );
    }

    public function test_nothing_is_sent_when_disabled(): void
    {
        $this->engineAnswers(200);
        config(['services.indexnow.enabled' => false]);
        $this->product('kept');

        $this->artisan('indexnow:submit', ['--all' => true])->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_a_refused_submission_is_retried_next_time(): void
    {
        $this->engineAnswers(429);
        $this->product('kept');

        $this->artisan('indexnow:submit')->assertExitCode(1);

        $this->assertNull(Cache::get(IndexNow::LAST_RUN_KEY));
    }
}
