<?php

namespace Tests\Unit;

use App\Services\QrisPayloadService;
use Tests\TestCase;

class QrisPayloadServiceTest extends TestCase
{
    private const STATIC_PAYLOAD = '00020101021126610014COM.GO-JEK.WWW01189360091438659284520210G8659284520303UMI51440014ID.CO.QRIS.WWW0215ID10243297931020303UMI5204729953033605802ID5911Aksa Xiterz6006MALANG61056515362070703A0163045DEF';

    public function test_static_merchant_payload_is_valid_and_keeps_expected_identity(): void
    {
        $service = new QrisPayloadService;

        $this->assertTrue($service->validate(self::STATIC_PAYLOAD));
        $this->assertSame('Aksa Xiterz', $service->merchantName(self::STATIC_PAYLOAD));
        $this->assertStringContainsString('010211', self::STATIC_PAYLOAD);
    }

    public function test_invalid_checksum_is_rejected(): void
    {
        $service = new QrisPayloadService;

        $this->assertFalse($service->validate(substr(self::STATIC_PAYLOAD, 0, -1).'0'));
    }
}
