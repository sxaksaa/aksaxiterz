<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\LicenseStock;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\QrisPayloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

class GopayQrisCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const STATIC_QRIS = '00020101021126610014COM.GO-JEK.WWW01189360091438659284520210G8659284520303UMI51440014ID.CO.QRIS.WWW0215ID10243297931020303UMI5204729953033605802ID5911Aksa Xiterz6006MALANG61056515362070703A0163045DEF';

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for GoPay QRIS checkout tests.');
        }

        parent::setUp();

        config([
            'services.gopay_qris.enabled' => true,
            'services.gopay_qris.static_payload' => self::STATIC_QRIS,
            'services.gopay_qris.merchant_name' => 'Aksa Xiterz',
            'services.gopay_qris.merchant_reference' => 'ID102432979310',
            'services.gopay_qris.expires_minutes' => 10,
            'services.gopay_qris.recovery_hours' => 24,
            'services.gopay_qris.unique_max' => 999,
            'services.gopay_qris.webhook_token' => 'checkout-test-token',
            'services.gopay_qris.webhook_secret' => 'checkout-test-secret',
            'services.gopay_qris.allowed_devices' => ['aksa-gopay-primary'],
        ]);
    }

    public function test_checkout_locks_exact_total_in_valid_dynamic_qris(): void
    {
        [$product, $package] = $this->catalogWithStock(2);
        $user = User::factory()->create();

        $result = app(PaymentService::class)->createGopayQrisPayment(
            $user,
            $product->id,
            $package->id
        );

        $order = $result['order'];
        $payment = $result['gopay_qris_payment'];
        $total = (int) $payment['total_payment'];

        $this->assertSame('gopay_qris', $order->payment_method);
        $this->assertSame(50_000, (int) $payment['base_amount']);
        $this->assertSame(353, (int) $payment['platform_fee']);
        $this->assertGreaterThanOrEqual(1, (int) $payment['unique_amount']);
        $this->assertLessThanOrEqual(999, (int) $payment['unique_amount']);
        $this->assertSame(
            50_000 + (int) $payment['platform_fee'] + (int) $payment['unique_amount'],
            $total
        );
        $this->assertSame($total, (int) $order->price);
        $this->assertNull($order->payment_url);
        $this->assertNotNull($order->payment_match_key);
        $this->assertTrue(app(QrisPayloadService::class)->validate($payment['qr_payload']));
        $this->assertSame('12', $this->topLevelTag($payment['qr_payload'], '01'));
        $this->assertSame((string) $total, $this->topLevelTag($payment['qr_payload'], '54'));

        $reservation = LicenseStock::where('reserved_order_id', $order->id)->firstOrFail();
        $this->assertTrue($reservation->reserved_until->equalTo($order->expired_at));
    }

    public function test_open_orders_with_same_base_price_receive_distinct_exact_totals(): void
    {
        [$product, $package] = $this->catalogWithStock(2);

        $first = app(PaymentService::class)->createGopayQrisPayment(
            User::factory()->create(),
            $product->id,
            $package->id
        )['order'];
        $second = app(PaymentService::class)->createGopayQrisPayment(
            User::factory()->create(),
            $product->id,
            $package->id
        )['order'];

        $this->assertNotSame((int) $first->price, (int) $second->price);
        $this->assertNotSame($first->payment_match_key, $second->payment_match_key);
        $this->assertSame(2, LicenseStock::whereNotNull('reserved_order_id')->count());
    }

    public function test_checkout_stays_disabled_when_bridge_secret_is_missing(): void
    {
        config(['services.gopay_qris.webhook_secret' => '']);
        [$product, $package] = $this->catalogWithStock();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('GoPay QRIS checkout is not configured');

        app(PaymentService::class)->createGopayQrisPayment(
            User::factory()->create(),
            $product->id,
            $package->id
        );
    }

    public function test_recently_paid_amount_remains_reserved_during_reconciliation_window(): void
    {
        [$product, $package] = $this->catalogWithStock(2);
        $service = app(PaymentService::class);
        $paidOrder = $service->createGopayQrisPayment(
            User::factory()->create(),
            $product->id,
            $package->id
        )['order'];
        $paidOrder->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        $heldMatchKey = $paidOrder->payment_match_key;

        $service->createGopayQrisPayment(
            User::factory()->create(),
            $product->id,
            $package->id
        );

        $this->assertNotNull($heldMatchKey);
        $this->assertSame($heldMatchKey, $paidOrder->fresh()->payment_match_key);
    }

    private function catalogWithStock(int $stockCount = 1): array
    {
        $category = Category::create([
            'name' => 'GoPay QRIS',
            'slug' => 'gopay-qris-'.uniqid(),
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'GoPay QRIS Product '.uniqid(),
            'slug' => 'gopay-qris-product-'.uniqid(),
            'status' => Product::STATUS_READY,
            'is_visible' => true,
            'description' => 'GoPay QRIS checkout test product.',
        ]);
        $package = Package::create([
            'product_id' => $product->id,
            'name' => '1 Day',
            'price' => 50_000,
            'price_usdt' => 3,
        ]);

        for ($index = 1; $index <= $stockCount; $index++) {
            LicenseStock::create([
                'product_id' => $product->id,
                'package_id' => $package->id,
                'license_key' => "GOPAY-CHECKOUT-{$index}-".uniqid(),
                'is_sold' => false,
            ]);
        }

        return [$product, $package];
    }

    private function topLevelTag(string $payload, string $wanted): ?string
    {
        for ($offset = 0, $length = strlen($payload); $offset + 4 <= $length;) {
            $tag = substr($payload, $offset, 2);
            $size = (int) substr($payload, $offset + 2, 2);
            $value = substr($payload, $offset + 4, $size);

            if ($tag === $wanted) {
                return $value;
            }

            $offset += 4 + $size;
        }

        return null;
    }
}
