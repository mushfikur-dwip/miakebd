<?php

namespace Tests\Feature;

use App\Http\Requests\SiteRequest;
use App\Http\Requests\SliderRequest;
use App\Rules\SafeLink;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Slider, banner and community links are typed in the admin and rendered
 * straight into an href on the storefront. An href is an execution sink, so a
 * "javascript:" scheme stored here runs in our own origin for every shopper who
 * clicks the banner - and for the next admin who opens the home page.
 *
 * Anyone with the `settings` permission can write these fields, which is a much
 * wider group than full administrators.
 */
class SafeLinkTest extends TestCase
{
    private function passes(?string $link): bool
    {
        return !Validator::make(['link' => $link], ['link' => ['nullable', new SafeLink]])->fails();
    }

    public static function dangerousLinks(): array
    {
        return [
            'javascript'              => ['javascript:alert(document.cookie)'],
            'javascript mixed case'   => ['JaVaScRiPt:alert(1)'],
            'javascript with spaces'  => ['  javascript:alert(1)'],
            // Both of these still execute in an href; a plain prefix check
            // would wave them through.
            'javascript with a null'  => ["java\0script:alert(1)"],
            'javascript with a tab'   => ["java\tscript:alert(1)"],
            'javascript with newline' => ["java\nscript:alert(1)"],
            'data html'               => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'vbscript'                => ['vbscript:msgbox(1)'],
            'file'                    => ['file:///etc/passwd'],
        ];
    }

    #[DataProvider('dangerousLinks')]
    public function test_executable_schemes_are_rejected(string $link): void
    {
        $this->assertFalse($this->passes($link), "[{$link}] was accepted");
    }

    public static function legitimateLinks(): array
    {
        return [
            'https'            => ['https://suglow.com/brands/cerave'],
            'http'             => ['http://example.com'],
            'bare path'        => ['/brands/cerave'],
            'domain no scheme' => ['facebook.com/groups/klassy'],
            'mailto'           => ['mailto:hello@suglow.com'],
            'tel'              => ['tel:+8801709786330'],
            'empty'            => [''],
        ];
    }

    #[DataProvider('legitimateLinks')]
    public function test_ordinary_links_still_pass(string $link): void
    {
        $this->assertTrue($this->passes($link), "[{$link}] was rejected");
    }

    public function test_a_null_link_is_allowed(): void
    {
        $this->assertTrue($this->passes(null));
    }

    public function test_the_slider_form_enforces_it(): void
    {
        $rules = (new SliderRequest())->rules();

        $this->assertTrue(
            Validator::make(['link' => 'javascript:alert(1)'], ['link' => $rules['link']])->fails()
        );
        $this->assertFalse(
            Validator::make(['link' => '/brands/cerave'], ['link' => $rules['link']])->fails()
        );
    }

    public function test_the_community_link_setting_enforces_it(): void
    {
        $rules = (new SiteRequest())->rules();

        $this->assertTrue(
            Validator::make(
                ['site_community_link' => 'javascript:alert(1)'],
                ['site_community_link' => $rules['site_community_link']]
            )->fails()
        );
        $this->assertFalse(
            Validator::make(
                ['site_community_link' => 'https://chat.whatsapp.com/abc'],
                ['site_community_link' => $rules['site_community_link']]
            )->fails()
        );
    }
}
