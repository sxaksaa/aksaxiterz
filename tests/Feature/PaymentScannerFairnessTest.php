<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Services\BinancePayOrderVerifier;
use App\Services\DirectCryptoOrderVerifier;
use App\Services\OrderFulfillmentService;
use App\Services\PaymentService;
use App\Services\StockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentScannerFairnessTest extends TestCase
{
    use RefreshDatabase;

    public static function backlogs(): array
    {
        return [['unpaid'], ['provider_failure'], ['legacy']];
    }

    #[DataProvider('backlogs')]
    public function test_scheduled_crypto_scans_reach_a_paid_order_behind_a_full_backlog(string $mode): void
    {
        $this->freezeTime();
        Cache::setDefaultDriver('database');
        $paid = $this->backlog('crypto', $mode);
        $provider = Mockery::mock(PaymentService::class);
        $provider->shouldReceive('inspectDirectCryptoPayment')->andReturnUsing(function (Order $order) use ($paid, $mode) {
            if ($order->id !== $paid->id) {
                if ($mode === 'provider_failure') {
                    throw new \RuntimeException('Provider unavailable');
                }

                return ['transfer' => null];
            }

            return ['transfer' => ['tx_hash' => '0xfair-crypto', 'confirmed_at' => now()]];
        });
        $scanner = fn () => new DirectCryptoOrderVerifier($provider, app(OrderFulfillmentService::class));
        $this->assertSame(0, $scanner()->scanPending(100)['paid']);
        $this->assertSame('pending', $paid->fresh()->status);
        // A fresh scanner and frozen clock must still advance the durable cursor.
        $this->assertSame(1, $scanner()->scanPending(100)['paid']);
        $this->assertSame('paid', $paid->fresh()->status);
        $this->assertDatabaseHas('licenses', ['order_id' => $paid->order_id, 'user_id' => $paid->user_id]);
    }

    #[DataProvider('backlogs')]
    public function test_scheduled_binance_scans_reach_a_paid_order_behind_a_full_backlog(string $mode): void
    {
        $this->freezeTime();
        Cache::setDefaultDriver('database');
        $paid = $this->backlog('binance_pay', $mode);
        $provider = Mockery::mock(PaymentService::class);
        $history = ['transactions' => [[
            'orderType' => 'C2C', 'transactionId' => 'fair-binance',
            'transactionTime' => now()->getTimestampMs(), 'amount' => '1.100123', 'currency' => 'USDT',
        ]]];
        if ($mode === 'provider_failure') {
            $provider->shouldReceive('getBinancePayTransactions')->once()->ordered()->andThrow(new \RuntimeException('Provider unavailable'));
        }
        $provider->shouldReceive('getBinancePayTransactions')->andReturn($history);
        $scanner = fn () => new BinancePayOrderVerifier($provider, app(OrderFulfillmentService::class), app(StockReservationService::class));
        $this->assertSame(0, $scanner()->scanPending(100)['paid']);
        $this->assertSame('pending', $paid->fresh()->status);
        Cache::forget('payment-scan:binance-pay:cooldown');
        $this->assertSame(1, $scanner()->scanPending(100)['paid']);
        $this->assertSame('paid', $paid->fresh()->status);
        $this->assertDatabaseHas('licenses', ['order_id' => $paid->order_id, 'user_id' => $paid->user_id]);
    }

    public function test_explicit_binance_verification_still_includes_an_order_outside_the_first_batch(): void
    {
        $paid = $this->backlog('binance_pay', 'unpaid');
        $provider = Mockery::mock(PaymentService::class);
        $provider->shouldReceive('getBinancePayTransactions')->once()->andReturn(['transactions' => [[
            'orderType' => 'C2C', 'transactionId' => 'fair-explicit',
            'transactionTime' => now()->getTimestampMs(), 'amount' => '1.100123', 'currency' => 'USDT',
        ]]]);
        $scanner = new BinancePayOrderVerifier($provider, app(OrderFulfillmentService::class), app(StockReservationService::class));

        $this->assertSame('paid', $scanner->verify($paid)['status']);
        $this->assertDatabaseHas('licenses', ['order_id' => $paid->order_id]);
    }

    private function backlog(string $method, string $mode): Order
    {
        $user = User::factory()->create();
        $category = Category::create(['name' => 'Fairness', 'slug' => 'fairness']);
        $product = Product::create(['name' => 'Fairness', 'slug' => 'fairness', 'description' => 'Payment scanner regression fixture.', 'category_id' => $category->id, 'status' => Product::STATUS_READY]);
        $package = Package::create(['product_id' => $product->id, 'name' => '1 Day', 'price' => 20000, 'price_usdt' => 1.1]);
        $attributes = [
            'user_id' => $user->id, 'product_id' => $product->id, 'package_id' => $package->id,
            'payment_method' => $method, 'quantity' => 1, 'price' => 1.100123,
            'payment_payload' => ['type' => $method === 'crypto' ? 'direct_crypto' : 'binance_pay_personal', 'amount' => '1.100123', 'token' => 'USDT'],
        ];
        for ($i = 0; $i < 100; $i++) {
            Order::create(array_merge($attributes, [
                'order_id' => 'ORDER-BLOCKER-'.$i, 'status' => 'cancelled',
                'created_at' => now()->subHours(3)->addSeconds($i), 'expired_at' => now()->subHours(2),
                'payment_match_key' => hash('sha256', 'blocker-'.$i),
                'payment_payload' => $mode === 'legacy' ? ['type' => 'legacy'] : $attributes['payment_payload'],
            ]));
        }
        $paid = Order::create(array_merge($attributes, [
            'order_id' => 'ORDER-FAIR-PAID', 'status' => 'pending', 'created_at' => now()->subMinute(),
            'expired_at' => now()->addMinutes(10), 'payment_match_key' => hash('sha256', 'fair-paid'),
        ]));
        LicenseStock::create(['product_id' => $product->id, 'package_id' => $package->id, 'license_key' => 'FAIR-TEST-KEY', 'is_sold' => false]);

        return $paid;
    }
}
