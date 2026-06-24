<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecentPurchaseSocialProofTest extends TestCase
{
    use RefreshDatabase;

    public function test_storefront_shows_recent_paid_purchases_without_exposing_full_buyer_name(): void
    {
        [$product, $package] = $this->productWithPackage('Social Proof Tool', 'social-proof-tool', '7 Days');

        $paidBuyer = User::factory()->create([
            'name' => 'Akbar Rizki',
            'email' => 'akbar@example.com',
        ]);
        $this->createOrder($product, $package, $paidBuyer, [
            'order_id' => 'ORDER-PAID-SOCIAL',
            'status' => 'paid',
            'paid_at' => now()->subMinutes(8),
        ]);

        $pendingBuyer = User::factory()->create([
            'name' => 'Pending Person',
            'email' => 'pending@example.com',
        ]);
        $this->createOrder($product, $package, $pendingBuyer, [
            'order_id' => 'ORDER-PENDING-SOCIAL',
            'status' => 'pending',
            'paid_at' => null,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('data-recent-purchase-toast', false)
            ->assertSee('A***r', false)
            ->assertSee('Social Proof Tool', false)
            ->assertSee('7 Days', false)
            ->assertDontSee('Akbar Rizki', false)
            ->assertDontSee('P*****g', false);
    }

    public function test_product_detail_recent_purchase_feed_is_scoped_to_current_product(): void
    {
        [$targetProduct, $targetPackage] = $this->productWithPackage('Target Social Tool', 'target-social-tool', '7 Days');
        [$otherProduct, $otherPackage] = $this->productWithPackage('Outside Social Tool', 'outside-social-tool', '30 Days');

        $auroraBuyer = User::factory()->create(['name' => 'Akbar']);
        $otherBuyer = User::factory()->create(['name' => 'Other Buyer']);

        $this->createOrder($targetProduct, $targetPackage, $auroraBuyer, [
            'order_id' => 'ORDER-TARGET-PAID',
            'status' => 'paid',
            'paid_at' => now()->subMinutes(3),
        ]);
        $this->createOrder($otherProduct, $otherPackage, $otherBuyer, [
            'order_id' => 'ORDER-OUTSIDE-PAID',
            'status' => 'paid',
            'paid_at' => now()->subMinutes(2),
        ]);

        $this->get(route('products.show', $targetProduct))
            ->assertOk()
            ->assertSee('data-recent-purchase-toast', false)
            ->assertSee('A***r', false)
            ->assertDontSee('O***r', false)
            ->assertDontSee('Outside Social Tool', false);
    }

    private function productWithPackage(string $name, string $slug, string $packageName): array
    {
        $category = Category::create([
            'name' => 'PC',
            'slug' => $slug.'-category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => $slug,
            'status' => Product::STATUS_READY,
            'description' => $name.' description.',
        ]);

        $package = Package::create([
            'product_id' => $product->id,
            'name' => $packageName,
            'price' => 20000,
            'price_usdt' => 1.25,
        ]);

        return [$product, $package];
    }

    private function createOrder(Product $product, Package $package, User $user, array $attributes): Order
    {
        $quantity = (int) ($attributes['quantity'] ?? 1);

        $order = Order::create(array_merge([
            'order_id' => 'ORDER-SOCIAL-'.uniqid(),
            'product_id' => $product->id,
            'package_id' => $package->id,
            'user_id' => $user->id,
            'status' => 'paid',
            'payment_method' => 'pakasir',
            'price' => $package->price,
            'quantity' => $quantity,
            'paid_at' => now()->subMinutes(5),
        ], $attributes));

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'product_name' => $product->name,
            'package_name' => $package->name,
            'quantity' => $quantity,
            'unit_price_idr' => $package->price,
            'unit_price_usdt' => $package->price_usdt,
            'line_total_idr' => $package->price * $quantity,
            'line_total_usdt' => $package->price_usdt * $quantity,
        ]);

        return $order;
    }
}
