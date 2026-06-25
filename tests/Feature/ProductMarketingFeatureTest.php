<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

class ProductMarketingFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is not available in this PHP environment.');
        }

        parent::setUp();
    }

    public function test_storefront_uses_paid_orders_for_sales_badges_and_faq_lives_on_legal_page(): void
    {
        [$bestSeller, $bestSellerPackage] = $this->productWithPackage('Aurora Best', 'aurora-best', '30 Days');
        [$popular, $popularPackage] = $this->productWithPackage('Aurora Popular', 'aurora-popular', '7 Days');
        [$updating] = $this->productWithPackage('Aurora Updating', 'aurora-updating', '1 Day');
        $updating->update(['status' => Product::STATUS_UPDATING]);

        $this->createPaidOrder($bestSeller, $bestSellerPackage, [
            'order_id' => 'ORDER-BEST-OLD',
            'quantity' => 5,
            'paid_at' => now()->subDays(45),
        ]);

        $this->createPaidOrder($popular, $popularPackage, [
            'order_id' => 'ORDER-POPULAR-RECENT',
            'quantity' => 2,
            'paid_at' => now()->subDays(2),
        ]);

        Voucher::create([
            'code' => 'HEMAT10',
            'discount_percent' => 10,
            'max_discount' => 15000,
            'max_discount_usdt' => 0.5,
            'max_discount_usdc' => 0.5,
            'minimum_purchase' => 20000,
            'is_active' => true,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Discord member promo')
            ->assertSee('Member voucher drops available')
            ->assertSee('Claim on Discord')
            ->assertSee('Member vouchers, restock alerts, and setup help.')
            ->assertDontSee('HEMAT10')
            ->assertDontSee('10% off up to Rp 15.000')
            ->assertSee('Aurora Best')
            ->assertSee('Best Seller')
            ->assertSee('Aurora Popular')
            ->assertSee('Popular')
            ->assertSee('Aurora Updating')
            ->assertSee('Update alerts in Discord');

        $this->get(route('products.show', $bestSeller))
            ->assertOk()
            ->assertDontSee('Before checkout')
            ->assertDontSee('Where will my license appear?')
            ->assertSee('Discord member promo')
            ->assertSee('Claim on Discord')
            ->assertDontSee('HEMAT10')
            ->assertSee('Best Seller');

        $this->get(route('faq'))
            ->assertOk()
            ->assertSee('FAQ')
            ->assertSee('Where will my license appear?')
            ->assertSee('What if the order is still pending?');
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

    private function createPaidOrder(Product $product, Package $package, array $attributes = []): Order
    {
        $user = isset($attributes['user_id']) ? null : User::factory()->create();
        $quantity = (int) ($attributes['quantity'] ?? 1);

        $order = Order::create(array_merge([
            'order_id' => 'ORDER-MARKETING-'.uniqid(),
            'product_id' => $product->id,
            'package_id' => $package->id,
            'user_id' => $user?->id ?? $attributes['user_id'],
            'status' => 'paid',
            'payment_method' => 'pakasir',
            'price' => $package->price * $quantity,
            'quantity' => $quantity,
            'paid_at' => now()->subMinutes(10),
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
