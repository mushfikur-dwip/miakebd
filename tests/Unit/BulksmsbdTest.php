<?php

namespace Tests\Unit;

use App\Http\SmsGateways\Gateways\Bulksmsbd;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

class BulksmsbdTest extends TestCase
{
    #[DataProvider('bangladeshPhoneNumbers')]
    public function test_it_normalizes_bangladesh_phone_numbers(
        string $countryCode,
        string $phone,
        string $expected
    ): void {
        $reflection = new ReflectionClass(Bulksmsbd::class);
        $gateway = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('normalizeBangladeshNumber');

        $this->assertSame($expected, $method->invoke($gateway, $countryCode, $phone));
    }

    public static function bangladeshPhoneNumbers(): array
    {
        return [
            'local number with plus country code' => ['+880', '01903812566', '8801903812566'],
            'local number without leading zero' => ['880', '1903812566', '8801903812566'],
            'already international' => ['+880', '8801903812566', '8801903812566'],
            'default Bangladesh country code' => ['', '01903812566', '8801903812566'],
        ];
    }
}
