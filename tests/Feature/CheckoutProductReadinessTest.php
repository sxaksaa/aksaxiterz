<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Category;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

class CheckoutProductReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for checkout readiness tests.');
        }

        parent::setUp();
    }

    public function test_all_single_product_payment_methods_reject_an_updating_product(): void
    {
        [$user, $product, $package] = $this->updatingCatalogItem();
        $message = 'This product is not ready for automatic checkout.';

        foreach ([
            ["/process-order/{$product->id}", [
                'package_id' => $package->id,
                'quantity' => 1,
            ]],
            ["/pay-crypto/{$product->id}", [
                'package_id' => $package->id,
                'quantity' => 1,
                'coin' => 'usdtbsc',
            ]],
            ["/pay-binance/{$product->id}", [
                'package_id' => $package->id,
                'quantity' => 1,
                'token' => 'usdt',
            ]],
        ] as [$url, $payload]) {
            $this->actingAs($user)
                ->postJson($url, $payload)
                ->assertUnprocessable()
                ->assertJsonPath('message', $message);
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('license_stocks', [
            'product_id' => $product->id,
            'package_id' => $package->id,
            'is_sold' => false,
            'reserved_order_id' => null,
        ]);
    }

    public function test_pay_again_rejects_an_updating_product_before_cancelling_or_replacing_the_old_order(): void
    {
        [$user, $product, $package] = $this->updatingCatalogItem();
        $oldOrder = $this->retryOrder($user, $product->id, $package, 'ORDER-UPDATING-RETRY');
        $oldOrder->update(['status' => 'pending']);

        $this->actingAs($user)
            ->postJson("/pay-again/{$oldOrder->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This product is not ready for automatic checkout.');

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('pending', $oldOrder->fresh()->status);
        $this->assertNull($oldOrder->fresh()->replaced_by);
    }

    public function test_pay_again_does_not_replace_orders_for_hidden_or_missing_products(): void
    {
        [$user, $product, $package] = $this->updatingCatalogItem();
        $product->update([
            'status' => Product::STATUS_READY,
            'is_visible' => false,
        ]);
        $hiddenOrder = $this->retryOrder($user, $product->id, $package, 'ORDER-HIDDEN-RETRY');
        $missingOrder = $this->retryOrder($user, 999999, $package, 'ORDER-MISSING-RETRY');

        foreach ([$hiddenOrder, $missingOrder] as $order) {
            $this->actingAs($user)
                ->postJson("/pay-again/{$order->id}")
                ->assertUnprocessable()
                ->assertJsonPath('message', 'This product is not ready for automatic checkout.');

            $this->assertSame('cancelled', $order->fresh()->status);
            $this->assertNull($order->fresh()->replaced_by);
        }

        $this->assertDatabaseCount('orders', 2);
    }

    public function test_cart_checkout_rejects_an_item_that_changed_to_updating(): void
    {
        [$user, $product, $package] = $this->updatingCatalogItem();
        CartItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]);

        $this->actingAs($user)
            ->postJson(route('cart.checkout'), ['payment_method' => 'pakasir'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This product is not ready for automatic checkout.');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_updating_product_detail_keeps_stocked_packages_out_of_checkout(): void
    {
        [, $product] = $this->updatingCatalogItem();

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('data-product-checkout-ready="false"', false)
            ->assertSee('data-checkout-paused', false)
            ->assertSee('Checkout is temporarily paused while this product is updating.')
            ->assertSee('data-package-checkout-enabled="false"', false)
            ->assertSee('data-request-mode="update-alert"', false)
            ->assertDontSee('data-package-checkout-enabled="true"', false);
    }

    private function retryOrder(User $user, int $productId, Package $package, string $orderId): Order
    {
        return Order::create([
            'order_id' => $orderId,
            'user_id' => $user->id,
            'product_id' => $productId,
            'package_id' => $package->id,
            'quantity' => 1,
            'status' => 'cancelled',
            'payment_method' => 'crypto',
            'price' => $package->price_usdt,
            'payment_payload' => [
                'type' => 'direct_crypto',
                'network' => 'usdtbsc',
            ],
            'expired_at' => now()->subMinute(),
        ]);
    }

    private function updatingCatalogItem(): array
    {
        $user = User::factory()->create();
        $category = Category::create([
            'name' => 'Readiness Test',
            'slug' => 'readiness-test-'.uniqid(),
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Updating Product',
            'slug' => 'updating-product-'.uniqid(),
            'status' => Product::STATUS_UPDATING,
            'is_visible' => true,
            'description' => 'Product readiness regression test.',
        ]);
        $package = Package::create([
            'product_id' => $product->id,
            'name' => '30 Days',
            'price' => 20000,
            'price_usdt' => 1.25,
        ]);

        LicenseStock::create([
            'product_id' => $product->id,
            'package_id' => $package->id,
            'license_key' => 'UPDATING-KEY-'.uniqid(),
            'is_sold' => false,
        ]);

        return [$user, $product, $package];
    }
}
