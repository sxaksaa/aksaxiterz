<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class PaymentPresentationTest extends TestCase
{
    public function test_csrf_token_endpoint_returns_current_token(): void
    {
        $this->get(route('csrf-token'))
            ->assertOk()
            ->assertJsonStructure(['token'])
            ->assertJson(fn ($json) => $json
                ->whereType('token', 'string')
                ->etc()
            );
    }

    public function test_direct_crypto_orders_render_address_and_verify_actions(): void
    {
        $order = $this->fakeOrder([
            'order_id' => 'ORDER-VERIFYTEST',
            'status' => 'pending',
        ]);

        $html = $this->renderOrders([$order]);

        $this->assertStringContainsString('USDT Address', $html);
        $this->assertStringContainsString('View Address', $html);
        $this->assertStringContainsString('data-crypto-checkout=', $html);
        $this->assertStringContainsString('class="sync-crypto-form"', $html);
        $this->assertStringContainsString('data-order-id="ORDER-VERIFYTEST"', $html);
        $this->assertStringContainsString('action="/cancel-order/1"', $html);
        $this->assertStringContainsString('Cancel Order', $html);
        $this->assertStringNotContainsString('Order ID copied', $html);
        $this->assertStringNotContainsString('href="/sync-crypto-order/ORDER-VERIFYTEST"', $html);
    }

    public function test_direct_crypto_orders_render_usdc_token_label(): void
    {
        $order = $this->fakeOrder([
            'order_id' => 'ORDER-USDC',
            'payment_payload' => [
                'type' => 'direct_crypto',
                'token' => 'USDC',
                'network' => 'usdcbsc',
                'network_label' => 'USDC BNB Smart Chain (BEP20)',
                'network_short_label' => 'USDC BEP20',
                'address' => '0x1111111111111111111111111111111111111111',
                'contract' => '0x8ac76a51cc950d9822d68b83fe1ad97b32cd580d',
                'amount' => '1.100123',
                'base_amount' => '1.100000',
                'unique_amount' => '0.000123',
                'decimals' => 18,
                'expires_at' => now()->addHour()->toIso8601String(),
            ],
        ]);

        $html = $this->renderOrders([$order]);

        $this->assertStringContainsString('USDC Address', $html);
        $this->assertStringContainsString('1.100123 USDC', $html);
        $this->assertStringNotContainsString('USDT Address', $html);
    }

    public function test_cancelled_crypto_orders_within_recovery_render_verify_sent_without_address(): void
    {
        $order = $this->fakeOrder([
            'order_id' => 'ORDER-CANCELLED',
            'status' => 'cancelled',
        ]);

        $html = $this->renderOrders([$order]);

        $this->assertStringContainsString('Closed', $html);
        $this->assertStringNotContainsString('Verifying', $html);
        $this->assertStringContainsString('Verify Sent Payment', $html);
        $this->assertStringContainsString('data-order-id="ORDER-CANCELLED"', $html);
        $this->assertStringNotContainsString('View Address', $html);
        $this->assertStringNotContainsString('action="/cancel-order/1"', $html);
    }

    public function test_expired_crypto_orders_within_recovery_render_verify_sent_without_address(): void
    {
        $order = $this->fakeOrder([
            'expired_at' => now()->subSecond(),
        ]);

        $html = $this->renderOrders([$order]);

        $this->assertStringContainsString('Closed', $html);
        $this->assertStringNotContainsString('View Address', $html);
        $this->assertStringContainsString('Verify Sent Payment', $html);
        $this->assertStringContainsString('class="sync-crypto-form"', $html);
    }

    public function test_crypto_orders_after_recovery_do_not_render_payment_actions(): void
    {
        $order = $this->fakeOrder([
            'status' => 'cancelled',
            'expired_at' => now()->subHours(25),
        ]);

        $html = $this->renderOrders([$order]);

        $this->assertStringContainsString('Closed', $html);
        $this->assertStringNotContainsString('View Address', $html);
        $this->assertStringNotContainsString('Verify Sent Payment', $html);
        $this->assertStringNotContainsString('class="sync-crypto-form"', $html);
    }

    public function test_old_cancelled_crypto_order_hides_self_service_verify_before_backend_recovery_ends(): void
    {
        config([
            'services.crypto_direct.recovery_hours' => 24,
            'services.crypto_direct.self_service_verify_minutes' => 60,
        ]);

        $order = $this->fakeOrder([
            'status' => 'cancelled',
            'expired_at' => now()->subHours(2),
        ]);

        $html = $this->renderOrders([$order]);

        $this->assertStringContainsString('Closed', $html);
        $this->assertStringNotContainsString('Verify Sent Payment', $html);
        $this->assertStringNotContainsString('class="sync-crypto-form"', $html);
    }

    public function test_legacy_pakasir_orders_remain_readable_without_dead_provider_actions(): void
    {
        $order = $this->fakeOrder([
            'order_id' => 'ORDER-PAKASIR',
            'payment_method' => 'pakasir',
            'price' => 10000,
            'payment_url' => 'https://app.pakasir.com/pay/aksaxiterz/10000?order_id=ORDER-PAKASIR',
            'payment_payload' => [
                'amount' => 10000,
                'fee' => 380,
                'total_payment' => 10380,
                'payment_method' => 'qris',
                'payment_number' => '00020101021226570011ID.DUMMY.QRIS',
                'expired_at' => now()->addMinutes(5)->toIso8601String(),
            ],
            'expired_at' => now()->addMinutes(5),
        ]);

        $html = $this->renderOrders([$order]);

        $this->assertStringContainsString('QRIS', $html);
        $this->assertStringNotContainsString('QRIS (Pakasir)', $html);
        $this->assertStringContainsString('ORDER-PAKASIR', $html);
        $this->assertStringNotContainsString('sync-pakasir-form', $html);
        $this->assertStringNotContainsString('data-pakasir-checkout=', $html);
        $this->assertStringNotContainsString('app.pakasir.com', $html);
        $this->assertStringNotContainsString('Waiting for QRIS payment', $html);
        $this->assertStringNotContainsString('Need Help?', $html);
        $this->assertStringNotContainsString('Support message copied', $html);
        $this->assertStringNotContainsString('Pay Again', $html);
    }

    public function test_existing_order_progress_timeline_remains_the_single_status_display(): void
    {
        $pending = $this->fakeOrder([
            'order_id' => 'ORDER-STATE-PENDING',
            'payment_method' => 'gopay_qris',
        ]);
        $cancelled = $this->fakeOrder([
            'order_id' => 'ORDER-STATE-CANCELLED',
            'status' => 'cancelled',
            'expired_at' => now()->addHour(),
        ]);
        $expired = $this->fakeOrder([
            'order_id' => 'ORDER-STATE-EXPIRED',
            'status' => 'cancelled',
            'payment_method' => 'gopay_qris',
            'expired_at' => now()->subMinute(),
        ]);
        $cancelled->updated_at = now();
        $expired->updated_at = now();

        $html = $this->renderOrders([$pending, $cancelled, $expired]);

        $this->assertStringContainsString('ORDER-STATE-PENDING', $html);
        $this->assertStringContainsString('aria-label="Order progress"', $html);
        $this->assertStringContainsString('data-order-timeline', $html);
        $this->assertStringContainsString('order-timeline-track', $html);
        $this->assertStringContainsString('data-order-id="ORDER-STATE-PENDING"', $html);
        $this->assertStringContainsString('<span>Created</span>', $html);
        $this->assertStringContainsString('<span>Closed</span>', $html);
        $this->assertStringContainsString('<span>Paid</span>', $html);
        $this->assertStringContainsString('<span>Delivered</span>', $html);
        $this->assertStringNotContainsString('order-status-summary', $html);

        $expiredHtml = $this->renderOrders([$expired]);
        $this->assertStringContainsString('This invoice is closed. Start a new checkout when you are ready.', $expiredHtml);
        $this->assertStringNotContainsString('This checkout was cancelled. No payment action is needed.', $expiredHtml);
    }

    public function test_binance_pay_orders_render_pay_id_and_automatic_verification_actions(): void
    {
        $order = $this->fakeOrder([
            'order_id' => 'ORDER-BINANCE-PAY',
            'payment_method' => 'binance_pay',
            'price' => 1.100123,
            'payment_payload' => [
                'type' => 'binance_pay_personal',
                'token' => 'USDT',
                'pay_id' => '123456789',
                'qr_content' => '',
                'amount' => '1.100123',
                'base_amount' => '1.100000',
                'unique_amount' => '0.000123',
                'expires_at' => now()->addMinutes(10)->toIso8601String(),
            ],
            'expired_at' => now()->addMinutes(10),
        ]);

        $html = $this->renderOrders([$order]);

        $this->assertStringContainsString('Binance Pay', $html);
        $this->assertStringContainsString('View Binance Pay', $html);
        $this->assertStringContainsString('data-binance-pay-checkout=', $html);
        $this->assertStringContainsString('class="sync-binance-pay-form"', $html);
        $this->assertStringContainsString('1.100123 USDT', $html);
        $this->assertStringNotContainsString('USDT Address', $html);
    }

    public function test_cancelled_orders_do_not_render_extra_payment_actions(): void
    {
        $order = $this->fakeOrder([
            'status' => 'cancelled',
            'payment_method' => 'pakasir',
        ]);

        $html = $this->renderOrders([$order]);

        $this->assertStringContainsString('Closed', $html);
        $this->assertStringNotContainsString('Start New Checkout', $html);
        $this->assertStringNotContainsString('/product/1', $html);
        $this->assertStringNotContainsString('No action', $html);
    }

    public function test_paid_orders_render_paid_timestamp(): void
    {
        $paidAt = now()->setTime(13, 14, 15);
        $order = $this->fakeOrder([
            'status' => 'paid',
            'payment_method' => 'pakasir',
            'paid_at' => $paidAt,
            'expired_at' => now()->subMinute(),
        ]);

        $html = $this->renderOrders([$order]);
        $expectedTime = $paidAt->timezone(config('app.timezone'))->format('H:i:s').' WIB';

        $this->assertStringContainsString('Created at', $html);
        $this->assertStringContainsString('Paid at', $html);
        $this->assertStringContainsString($expectedTime, $html);
        $this->assertStringNotContainsString('View License', $html);
        $this->assertStringContainsString('Open Licenses', $html);
        $this->assertStringContainsString('/licenses?order=ORDER-TEST#license-ORDER-TEST', $html);
    }

    public function test_qris_renderer_preserves_scan_quiet_zone_and_order_redirect_copy(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString('margin: 20,', $javascript);
        $this->assertStringContainsString(
            "redirectUrl.startsWith('/orders') ? 'Orders' : 'My Licenses'",
            $javascript
        );
        $this->assertStringContainsString(
            'startPaymentSuccessRedirect(redirectUrl, redirectDelay, countdown, redirectLabel);',
            $javascript
        );
    }

    private function fakeOrder(array $attributes = []): Order
    {
        $product = new Product([
            'name' => 'Test Product',
            'description' => 'Test product description.',
        ]);
        $product->id = 1;

        $package = new Package([
            'name' => '1 Day',
            'price' => 10000,
            'price_usdt' => 1,
        ]);
        $package->id = 1;

        $order = new Order(array_merge([
            'order_id' => 'ORDER-TEST',
            'product_id' => 1,
            'user_id' => 1,
            'status' => 'pending',
            'payment_method' => 'crypto',
            'price' => 1.100123,
            'package_id' => 1,
            'payment_url' => null,
            'payment_payload' => [
                'type' => 'direct_crypto',
                'token' => 'USDT',
                'network' => 'usdtbsc',
                'network_label' => 'BSC BNB Smart Chain (BEP20)',
                'network_short_label' => 'BEP20',
                'address' => '0x1111111111111111111111111111111111111111',
                'contract' => '0x55d398326f99059fF775485246999027B3197955',
                'amount' => '1.100123',
                'base_amount' => '1.100000',
                'unique_amount' => '0.000123',
                'decimals' => 18,
                'expires_at' => now()->addHour()->toIso8601String(),
            ],
            'expired_at' => now()->addHour(),
        ], $attributes));
        $order->id = 1;
        $order->created_at = now()->subMinutes(5);
        $order->setRelation('product', $product);
        $order->setRelation('package', $package);

        return $order;
    }

    private function renderOrders(array $orders): string
    {
        $paginator = new LengthAwarePaginator(collect($orders), count($orders), 8, 1, [
            'path' => '/orders',
        ]);

        return view('partials.orders-list', ['orders' => $paginator])->render();
    }
}
