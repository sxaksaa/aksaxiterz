<?php

namespace Tests\Unit;

use App\Services\QrisPayloadService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QrisPayloadServiceTest extends TestCase
{
    private const STATIC_PAYLOAD = '00020101021126610014COM.GO-JEK.WWW01189360091438659284520210G8659284520303UMI51440014ID.CO.QRIS.WWW0215ID10243297931020303UMI5204729953033605802ID5911Aksa Xiterz6006MALANG61056515362070703A0163045DEF';

    #[DataProvider('amounts')]
    public function test_static_payload_becomes_valid_dynamic_payload(int $amount, string $amountField): void
    {
        $service = new QrisPayloadService;

        $this->assertTrue($service->validate(self::STATIC_PAYLOAD));

        $dynamic = $service->dynamic(self::STATIC_PAYLOAD, $amount);

        $this->assertTrue($service->validate($dynamic));
        $this->assertStringContainsString('010212', $dynamic);
        $this->assertStringContainsString($amountField, $dynamic);
        $this->assertSame('Aksa Xiterz', $service->merchantName($dynamic));
        $this->assertNotSame(self::STATIC_PAYLOAD, $dynamic);
    }

    public static function amounts(): array
    {
        return [
            'small' => [1, '54011'],
            'unique checkout' => [50_123, '540550123'],
            'QRIS limit' => [10_000_000, '540810000000'],
        ];
    }

    public function test_invalid_checksum_is_rejected(): void
    {
        $service = new QrisPayloadService;

        $this->assertFalse($service->validate(substr(self::STATIC_PAYLOAD, 0, -1).'0'));
    }
}
