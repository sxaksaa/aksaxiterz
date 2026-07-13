<?php

namespace Tests\Feature;

use App\Http\Middleware\ExpirePendingOrdersFromTraffic;
use App\Models\Category;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

class ProductStockFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for product stock feed tests.');
        }

        parent::setUp();

        $this->withoutMiddleware(ExpirePendingOrdersFromTraffic::class);
    }

    public function test_public_feed_uses_available_stock_semantics_and_hides_private_products(): void
    {
        $existingVisibleProducts = Product::visible()->count();
        $existingVisibleStock = LicenseStock::query()
            ->available()
            ->whereHas('product', fn ($query) => $query->visible())
            ->count();
        $user = User::factory()->create();
        $category = Category::create(['name' => 'PC', 'slug' => 'stock-feed-pc']);
        [$product, $package] = $this->productWithPackage($category, 'Visible Product', 'visible-product');
        [$secondProduct, $secondPackage] = $this->productWithPackage($category, 'Second Product', 'second-product');
        [$zeroStockProduct] = $this->productWithPackage($category, 'Zero Stock Product', 'zero-stock-product');
        [$hiddenProduct, $hiddenPackage] = $this->productWithPackage(
            $category,
            'Hidden Product',
            'hidden-product',
            false
        );

        $this->stock($product, $package, 'VISIBLE-AVAILABLE');
        $this->stock($product, $package, 'VISIBLE-SOLD', ['is_sold' => true]);

        $activeOrder = $this->order($user, $product, $package, 'ORDER-ACTIVE-RESERVATION');
        $this->stock($product, $package, 'VISIBLE-ACTIVE-RESERVATION', [
            'reserved_order_id' => $activeOrder->id,
            'reserved_until' => now()->addHour(),
        ]);

        $cancelledOrder = $this->order($user, $product, $package, 'ORDER-CANCELLED-RESERVATION', [
            'status' => 'cancelled',
        ]);
        $this->stock($product, $package, 'VISIBLE-CANCELLED-RESERVATION', [
            'reserved_order_id' => $cancelledOrder->id,
            'reserved_until' => now()->addHour(),
        ]);

        $expiredCryptoOrder = $this->order($user, $product, $package, 'ORDER-EXPIRED-CRYPTO');
        $this->stock($product, $package, 'VISIBLE-EXPIRED-CRYPTO', [
            'reserved_order_id' => $expiredCryptoOrder->id,
            'reserved_until' => now()->subMinute(),
        ]);

        $expiredQrisOrder = $this->order($user, $product, $package, 'ORDER-EXPIRED-QRIS', [
            'payment_method' => 'pakasir',
        ]);
        $this->stock($product, $package, 'VISIBLE-EXPIRED-QRIS', [
            'reserved_order_id' => $expiredQrisOrder->id,
            'reserved_until' => now()->subMinute(),
        ]);

        $this->stock($secondProduct, $secondPackage, 'SECOND-AVAILABLE');
        $this->stock($hiddenProduct, $hiddenPackage, 'HIDDEN-AVAILABLE-1');
        $this->stock($hiddenProduct, $hiddenPackage, 'HIDDEN-AVAILABLE-2');

        $response = $this->getJson(route('products.stocks'));

        $response
            ->assertOk()
            ->assertJsonCount($existingVisibleProducts + 3, 'products')
            ->assertJsonPath('total_available_stock', $existingVisibleStock + 4)
            ->assertJsonFragment([
                'id' => $product->id,
                'status' => Product::STATUS_READY,
                'status_label' => 'Ready',
                'available_stock' => 3,
            ])
            ->assertJsonFragment([
                'id' => $secondProduct->id,
                'status' => Product::STATUS_READY,
                'status_label' => 'Ready',
                'available_stock' => 1,
            ])
            ->assertJsonFragment([
                'id' => $zeroStockProduct->id,
                'status' => Product::STATUS_READY,
                'status_label' => 'Ready',
                'available_stock' => 0,
            ])
            ->assertJsonMissing(['id' => $hiddenProduct->id])
            ->assertDontSee('VISIBLE-AVAILABLE')
            ->assertDontSee('ORDER-ACTIVE-RESERVATION');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=0', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
    }

    public function test_feed_and_home_reflect_stock_changes_without_exposing_sensitive_data(): void
    {
        $existingVisibleStock = LicenseStock::query()
            ->available()
            ->whereHas('product', fn ($query) => $query->visible())
            ->count();
        $category = Category::create(['name' => 'Android', 'slug' => 'stock-feed-android']);
        [$product, $package] = $this->productWithPackage($category, 'Restock Product', 'restock-product');
        $stock = $this->stock($product, $package, 'RESTOCK-SENSITIVE-KEY');

        $this->getJson(route('products.stocks'))
            ->assertOk()
            ->assertJsonPath('total_available_stock', $existingVisibleStock + 1)
            ->assertJsonFragment([
                'id' => $product->id,
                'available_stock' => 1,
            ])
            ->assertDontSee('RESTOCK-SENSITIVE-KEY');

        $stock->update(['is_sold' => true]);

        $this->getJson(route('products.stocks'))
            ->assertOk()
            ->assertJsonPath('total_available_stock', $existingVisibleStock)
            ->assertJsonFragment([
                'id' => $product->id,
                'available_stock' => 0,
            ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('data-total-ready-stock', false)
            ->assertSee('data-product-stock-card', false)
            ->assertSee('data-product-stock-label', false)
            ->assertSee('productStockEndpoint', false);
    }

    private function productWithPackage(
        Category $category,
        string $name,
        string $slug,
        bool $isVisible = true
    ): array {
        $product = Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => $slug,
            'status' => Product::STATUS_READY,
            'is_visible' => $isVisible,
            'description' => $name.' description.',
        ]);
        $package = Package::create([
            'product_id' => $product->id,
            'name' => '30 Days',
            'price' => 20000,
            'price_usdt' => 1.25,
        ]);

        return [$product, $package];
    }

    private function order(
        User $user,
        Product $product,
        Package $package,
        string $orderId,
        array $attributes = []
    ): Order {
        return Order::create(array_merge([
            'order_id' => $orderId,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'status' => 'pending',
            'payment_method' => 'crypto',
            'price' => $package->price,
            'expired_at' => now()->addHour(),
        ], $attributes));
    }

    private function stock(
        Product $product,
        Package $package,
        string $licenseKey,
        array $attributes = []
    ): LicenseStock {
        return LicenseStock::create(array_merge([
            'product_id' => $product->id,
            'package_id' => $package->id,
            'license_key' => $licenseKey,
            'is_sold' => false,
        ], $attributes));
    }
}
