<?php

namespace Tests\Unit;

use App\Http\Resources\ProductSeoResource;
use App\Models\ProductSeo;
use App\Support\SeoSchema;
use Illuminate\Http\Request;
use Tests\TestCase;

class SeoMetadataTest extends TestCase
{
    public function test_plain_text_uses_only_the_first_description_block(): void
    {
        $description = "<p>Primary product summary.</p>\n\nQ: Is it available?\nYes.";

        $this->assertSame('Primary product summary.', SeoSchema::plainText($description));
    }

    public function test_plain_text_handles_rich_text_blank_paragraphs(): void
    {
        $description = '<p>Primary &amp; concise.</p><p><br></p><p>Question?</p><p>Answer.</p>';

        $this->assertSame('Primary & concise.', SeoSchema::plainText($description));
    }

    public function test_product_seo_resource_returns_keywords_as_an_array(): void
    {
        $seo = new ProductSeo([
            'title' => 'Example',
            'description' => 'Description',
            'meta_keyword' => '["skin care","cosmetics"]',
        ]);

        $data = (new ProductSeoResource($seo))->toArray(Request::create('/'));

        $this->assertSame(['skin care', 'cosmetics'], $data['meta_keyword']);
    }

    public function test_product_seo_resource_handles_invalid_keyword_json(): void
    {
        $seo = new ProductSeo(['meta_keyword' => 'not-json']);

        $data = (new ProductSeoResource($seo))->toArray(Request::create('/'));

        $this->assertSame([], $data['meta_keyword']);
    }
}
