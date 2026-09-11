<?php

namespace Tests\Feature;

use App\Http\Requests\SiteRequest;
use App\Http\Resources\SiteResource;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Settings > Site > Receipt footer text, printed under "Thank You" on the POS
 * sale receipt and its reprint.
 */
class ReceiptFooterSettingTest extends TestCase
{
    private function passes(?string $footer): bool
    {
        return Validator::make(
            ['site_receipt_footer' => $footer],
            ['site_receipt_footer' => (new SiteRequest())->rules()['site_receipt_footer']]
        )->passes();
    }

    public function test_the_footer_is_optional_and_holds_500_bangla_characters(): void
    {
        $this->assertTrue($this->passes(null));
        $this->assertTrue($this->passes("৭ দিনের মধ্যে রসিদ দেখিয়ে পণ্য বদলানো যাবে\nহটলাইন: 01700000000"));
        $this->assertTrue($this->passes(str_repeat('ধ', 500)));
        $this->assertFalse($this->passes(str_repeat('ধ', 501)));
    }

    /**
     * A live site has no stored value until Settings > Site is saved again, and
     * a bare index there would fail the settings screen.
     */
    public function test_a_site_that_never_saved_the_footer_reads_it_as_empty(): void
    {
        $info = array_fill_keys([
            'site_date_format', 'site_time_format', 'site_default_timezone', 'site_default_currency',
            'site_default_currency_symbol', 'site_currency_position', 'site_digit_after_decimal_point',
            'site_email_verification', 'site_phone_verification', 'site_default_language',
            'site_language_switch', 'site_app_debug', 'site_auto_update', 'site_android_app_link',
            'site_ios_app_link', 'site_copyright', 'site_online_payment_gateway', 'site_default_sms_gateway',
            'site_cash_on_delivery', 'site_non_purchase_product_maximum_quantity',
            'site_is_return_product_price_add_to_credit',
        ], null);

        $this->assertNull((new SiteResource($info))->toArray(request())['site_receipt_footer']);

        $info['site_receipt_footer'] = "Line one\nLine two";
        $this->assertSame("Line one\nLine two", (new SiteResource($info))->toArray(request())['site_receipt_footer']);
    }
}
