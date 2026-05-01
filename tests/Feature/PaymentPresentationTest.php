<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class PaymentPresentationTest extends TestCase
{
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

    public function test_cancelled_crypto_orders_do_not_render_as_verifying(): void
    {
        $order = $this->fakeOrder([
            'order_id' => 'ORDER-CANCELLED',
            'status' => 'cancelled',
        ]);

        $html = $this->renderOrders([$order]);

        $this->assertStringContainsString('Cancelled', $html);
        $this->assertStringNotContainsString('Verifying', $html);
        $this->assertStringNotContainsString('data-order-id="ORDER-CANCELLED"', $html);
        $this->assertStringNotContainsString('action="/cancel-order/1"', $html);
    }

    public function test_pakasir_orders_render_check_and_continue_actions(): void
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
        $this->assertStringContainsString('class="sync-pakasir-form"', $html);
        $this->assertStringContainsString('data-order-id="ORDER-PAKASIR"', $html);
        $this->assertStringContainsString('View QRIS', $html);
        $this->assertStringContainsString('data-pakasir-checkout=', $html);
        $this->assertStringNotContainsString('Waiting for QRIS payment', $html);
        $this->assertStringNotContainsString('Need Help?', $html);
        $this->assertStringNotContainsString('Support message copied', $html);
        $this->assertStringNotContainsString('Pay Again', $html);
    }

    public function test_cancelled_orders_do_not_render_extra_payment_actions(): void
    {
        $order = $this->fakeOrder([
            'status' => 'cancelled',
            'payment_method' => 'pakasir',
        ]);

        $html = $this->renderOrders([$order]);

        $this->assertStringContainsString('Cancelled', $html);
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
        $this->assertStringNotContainsString('/licenses?order=ORDER-TEST#license-ORDER-TEST', $html);
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
            'expired_at' => now()->subMinute(),
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
