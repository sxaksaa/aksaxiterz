<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class PaymentPresentationTest extends TestCase
{
    public function test_crypto_orders_render_verify_as_clickable_form(): void
    {
        $order = $this->fakeOrder([
            'order_id' => 'ORD-VERIFYTEST',
            'status' => 'pending',
        ]);

        $html = $this->renderOrders([$order]);

        $this->assertStringContainsString('class="sync-crypto-form"', $html);
        $this->assertStringContainsString('data-order-id="ORD-VERIFYTEST"', $html);
        $this->assertStringContainsString('action="/cancel-order/1"', $html);
        $this->assertStringContainsString('Cancel Order', $html);
        $this->assertStringNotContainsString('href="/sync-crypto-order/ORD-VERIFYTEST"', $html);
    }

    public function test_cancelled_crypto_orders_do_not_render_as_verifying(): void
    {
        $order = $this->fakeOrder([
            'order_id' => 'ORD-CANCELLED',
            'status' => 'cancelled',
        ]);

        $html = $this->renderOrders([$order]);

        $this->assertStringContainsString('Cancelled', $html);
        $this->assertStringNotContainsString('Verifying', $html);
        $this->assertStringNotContainsString('data-order-id="ORD-CANCELLED"', $html);
        $this->assertStringNotContainsString('action="/cancel-order/1"', $html);
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
            'order_id' => 'ORD-TEST',
            'product_id' => 1,
            'user_id' => 1,
            'status' => 'pending',
            'payment_method' => 'crypto',
            'price' => 1.10,
            'package_id' => 1,
            'payment_url' => 'https://nowpayments.io/payment/?iid=123',
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
