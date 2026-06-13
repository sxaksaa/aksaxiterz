<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentController;
use App\Models\Category;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use PDO;
use ReflectionMethod;
use Tests\TestCase;

class PayAgainIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for Pay Again isolation tests.');
        }

        parent::setUp();
    }

    public function test_pay_again_is_blocked_without_touching_another_pending_order(): void
    {
        [$user, $oldOrder, $unrelatedOrder] = $this->pendingOrders();

        $this->mock(PaymentService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('createCryptoPayment');
        });

        $this->actingAs($user)
            ->postJson("/pay-again/{$oldOrder->id}")
            ->assertConflict()
            ->assertJsonPath('order_id', $unrelatedOrder->order_id);

        $this->assertSame('pending', $oldOrder->fresh()->status);
        $this->assertNull($oldOrder->fresh()->replaced_by);
        $this->assertSame('pending', $unrelatedOrder->fresh()->status);
    }

    public function test_pay_again_replaces_only_the_selected_order_when_no_other_order_is_active(): void
    {
        [$user, $oldOrder, $unrelatedOrder] = $this->pendingOrders();
        $oldOrder->update(['quantity' => 3]);
        $unrelatedOrder->update([
            'status' => 'cancelled',
            'expired_at' => now()->subHour(),
        ]);

        $this->mock(PaymentService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createCryptoPayment')
                ->once()
                ->andReturnUsing(fn ($user, $productId, $packageId, $coin, $order) => [
                    'payment_url' => null,
                    'crypto_payment' => null,
                    'order' => $order,
                ]);
        });

        $this->actingAs($user)
            ->postJson("/pay-again/{$oldOrder->id}")
            ->assertOk();

        $this->assertSame('cancelled', $oldOrder->fresh()->status);
        $this->assertNotNull($oldOrder->fresh()->replaced_by);
        $this->assertSame('cancelled', $unrelatedOrder->fresh()->status);
        $this->assertSame(3, Order::findOrFail($oldOrder->fresh()->replaced_by)->quantity);
    }

    public function test_new_checkout_is_blocked_while_crypto_order_is_within_grace_period(): void
    {
        [$user, $order, $unrelatedOrder] = $this->pendingOrders();
        $unrelatedOrder->update(['status' => 'cancelled']);
        $order->update(['expired_at' => now()->subMinutes(5)]);

        $this->mock(PaymentService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('createCryptoPayment');
        });

        $this->actingAs($user)
            ->postJson("/pay-crypto/{$order->product_id}", [
                'package_id' => $order->package_id,
                'coin' => 'usdtbsc',
            ])
            ->assertConflict()
            ->assertJsonPath('order_id', $order->order_id);

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_cancelling_crypto_preserves_original_expiry(): void
    {
        [$user, $order] = $this->pendingOrders();
        $originalExpiry = $order->expired_at->copy();

        $this->actingAs($user)
            ->postJson("/cancel-order/{$order->id}")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $order->refresh();

        $this->assertSame('cancelled', $order->status);
        $this->assertTrue($order->expired_at->equalTo($originalExpiry));
    }

    public function test_atomic_cancel_does_not_overwrite_a_concurrently_paid_order(): void
    {
        [, $order] = $this->pendingOrders();
        $staleOrder = Order::findOrFail($order->id);

        DB::table('orders')
            ->where('id', $order->id)
            ->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

        $method = new ReflectionMethod(PaymentController::class, 'cancelPendingOrder');
        $result = $method->invoke(app(PaymentController::class), $staleOrder);

        $this->assertSame('paid', $result->status);
        $this->assertSame('paid', $order->fresh()->status);
    }

    private function pendingOrders(): array
    {
        $user = User::factory()->create();
        $category = Category::create([
            'name' => 'Retry Test',
            'slug' => 'retry-test',
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Retry Product',
            'slug' => 'retry-product',
            'status' => Product::STATUS_READY,
            'description' => 'Retry isolation test product.',
        ]);
        $package = Package::create([
            'product_id' => $product->id,
            'name' => '1 Day',
            'price' => 10000,
            'price_usdt' => 1,
        ]);

        $attributes = [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'status' => 'pending',
            'payment_method' => 'crypto',
            'price' => 1,
            'payment_payload' => [
                'type' => 'direct_crypto',
                'network' => 'usdtbsc',
            ],
            'expired_at' => now()->addHour(),
        ];

        return [
            $user,
            Order::create(array_merge($attributes, ['order_id' => 'ORDER-RETRY-TARGET'])),
            Order::create(array_merge($attributes, ['order_id' => 'ORDER-RETRY-UNRELATED'])),
        ];
    }
}
