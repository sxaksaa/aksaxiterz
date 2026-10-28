<?php

namespace Tests\Feature;

use App\Exceptions\UnpaidInvoiceLimitException;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use App\Services\DirectCheckoutReview;
use App\Services\OrderFulfillmentService;
use App\Services\PaymentService;
use App\Services\QrisPayloadService;
use App\Services\StockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
            'services.gopay_qris.recovery_hours' => 72,
            'services.gopay_qris.amount_quarantine_hours' => 168,
            'services.gopay_qris.unique_max' => 999,
            'services.gopay_qris.webhook_token' => 'checkout-test-token',
            'services.gopay_qris.webhook_secret' => 'checkout-test-secret',
            'services.gopay_qris.allowed_devices' => ['aksa-gopay-primary'],
        ]);
    }

    public function test_checkout_uses_original_static_qris_and_keeps_exact_total_for_manual_entry(): void
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
        $this->assertSame(self::STATIC_QRIS, $payment['qr_payload']);
        $this->assertSame('11', $this->topLevelTag($payment['qr_payload'], '01'));
        $this->assertNull($this->topLevelTag($payment['qr_payload'], '54'));
        $this->assertTrue($payment['requires_manual_amount']);

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

    public function test_config_verifier_requires_strong_distinct_webhook_credentials(): void
    {
        config([
            'services.gopay_qris.webhook_token' => 'same-short-value',
            'services.gopay_qris.webhook_secret' => 'same-short-value',
        ]);

        $this->assertSame(1, Artisan::call('payments:verify-gopay-config'));
        $output = Artisan::output();
        $this->assertStringContainsString('webhook_token must contain at least 32 characters', $output);
        $this->assertStringContainsString('webhook_secret must contain at least 32 characters', $output);
        $this->assertStringContainsString('webhook_token and webhook_secret must be different', $output);

        config([
            'services.gopay_qris.webhook_token' => str_repeat('t', 64),
            'services.gopay_qris.webhook_secret' => str_repeat('s', 64),
        ]);

        $this->assertSame(0, Artisan::call('payments:verify-gopay-config'));
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

    public function test_paid_amount_is_not_reused_after_recovery_ends_but_before_quarantine_ends(): void
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
            'paid_at' => now()->subHours(100),
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

    public function test_status_sync_distinguishes_waiting_closed_and_delivery_pending_states(): void
    {
        [$product, $package] = $this->catalogWithStock();
        $user = User::factory()->create();
        $order = app(PaymentService::class)->createGopayQrisPayment(
            $user,
            $product->id,
            $package->id
        )['order'];

        $this->actingAs($user)
            ->postJson('/sync-gopay-qris-order/'.$order->order_id)
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('message', 'Waiting for a matching GoPay Merchant notification.');

        $order->update([
            'status' => 'cancelled',
            'expired_at' => now()->subMinute(),
        ]);

        $this->postJson('/sync-gopay-qris-order/'.$order->order_id)
            ->assertStatus(202)
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath(
                'message',
                'This checkout is closed. An exact payment notification can still be recovered automatically for up to 72 hours.'
            );

        $order->update(['status' => 'paid']);

        $this->postJson('/sync-gopay-qris-order/'.$order->order_id)
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('delivery_pending', true)
            ->assertJsonPath('message', 'Payment verified. Your license delivery is being prepared.');
    }

    public function test_unpaid_retries_are_bounded_without_closing_the_current_invoice_or_blocking_other_buyers(): void
    {
        config(['services.gopay_qris.max_unpaid_invoices' => 2, 'services.gopay_qris.unique_max' => 4]);
        [$product, $package] = $this->catalogWithStock(2);
        $user = User::factory()->create();
        $first = app(PaymentService::class)->createGopayQrisPayment($user, $product->id, $package->id)['order'];
        $heldKey = $first->payment_match_key;

        $this->actingAs($user)->postJson('/pay-again/'.$first->id)->assertOk();
        $current = Order::findOrFail($first->fresh()->replaced_by);
        $this->postJson('/pay-again/'.$current->id)->assertStatus(429)
            ->assertJsonPath('message', 'You have too many unpaid QRIS invoices. Continue an existing payment or contact support before creating another.');

        $this->assertSame('pending', $current->fresh()->status);
        $this->assertNull($current->fresh()->replaced_by);
        $this->assertSame($heldKey, $first->fresh()->payment_match_key);
        $this->assertSame(2, Order::where('user_id', $user->id)->count());
        $this->assertSame(1, LicenseStock::where('reserved_order_id', $current->id)->count());
        $other = app(PaymentService::class)->createGopayQrisPayment(User::factory()->create(), $product->id, $package->id)['order'];
        $this->assertNotSame($current->payment_match_key, $other->payment_match_key);
    }

    public function test_direct_service_and_all_checkout_routes_share_the_retained_unpaid_budget(): void
    {
        config(['services.gopay_qris.max_unpaid_invoices' => 1]);
        [$product, $package] = $this->catalogWithStock();
        $user = User::factory()->create();
        $held = app(PaymentService::class)->createGopayQrisPayment($user, $product->id, $package->id)['order'];
        $this->actingAs($user)->postJson('/cancel-order/'.$held->id)->assertOk();
        $this->postJson('/pay-gopay-qris/'.$product->id, ['package_id' => $package->id])->assertStatus(429);
        $this->postJson(route('checkout.product.process', $product), [
            'payment_method' => 'gopay_qris', 'package_id' => $package->id, 'quantity' => 1,
            'review_signature' => app(DirectCheckoutReview::class)->signature($user, $product, $package, 1),
        ])->assertStatus(429);
        CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]);
        $cart = app(CartService::class);
        $this->postJson(route('cart.checkout'), [
            'payment_method' => 'gopay_qris', 'cart_signature' => $cart->signature($cart->items($user)),
        ])->assertStatus(429);
        $this->assertSame(1, Order::where('user_id', $user->id)->whereNotNull('payment_match_key')->count());
        $this->assertSame(0, LicenseStock::whereNotNull('reserved_order_id')->count());
        $this->expectException(UnpaidInvoiceLimitException::class);
        app(PaymentService::class)->createGopayQrisPayment($user, $product->id, $package->id);
    }

    public function test_paid_and_quarantine_expired_invoices_do_not_use_the_unpaid_budget(): void
    {
        config(['services.gopay_qris.max_unpaid_invoices' => 1]);
        [$product, $package] = $this->catalogWithStock(3);
        $user = User::factory()->create();
        $service = app(PaymentService::class);
        $paid = $service->createGopayQrisPayment($user, $product->id, $package->id)['order'];
        app(OrderFulfillmentService::class)->fulfill($paid);
        $paidKey = $paid->fresh()->payment_match_key;
        $expired = $service->createGopayQrisPayment($user, $product->id, $package->id)['order'];
        $expired->update(['status' => 'cancelled', 'expired_at' => now()->subHours(169)]);
        app(StockReservationService::class)->release($expired);
        $next = $service->createGopayQrisPayment($user, $product->id, $package->id)['order'];
        $this->assertNotNull($next->payment_match_key);
        $this->assertSame($paidKey, $paid->fresh()->payment_match_key);
        $this->assertNull($expired->fresh()->payment_match_key);
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
