<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Feature;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

class AdminProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is not available in this PHP environment.');
        }

        parent::setUp();
    }

    public function test_admin_can_update_product_and_package_prices(): void
    {
        [$admin, $product, $package, $category] = $this->makeCatalogProduct();

        $response = $this->actingAs($admin)->patch(route('admin.products.update', $product), [
            'category_id' => $category->id,
            'name' => 'Updated Product',
            'slug' => 'updated-product',
            'description' => 'Updated public description.',
            'status' => Product::STATUS_UPDATING,
        ]);

        $product->refresh();

        $response->assertRedirect(route('admin.products.edit', $product));
        $this->assertSame('Updated Product', $product->name);
        $this->assertSame('updated-product', $product->slug);
        $this->assertSame(Product::STATUS_UPDATING, $product->status);

        $response = $this->actingAs($admin)->patch(route('admin.packages.update', $package), [
            'package_name' => '30 Days',
            'package_price' => 125000,
            'package_price_usdt' => 7.5,
        ]);

        $package->refresh();

        $response->assertRedirect(route('admin.products.edit', $product));
        $this->assertSame('30 Days', $package->name);
        $this->assertSame(125000, $package->price);
        $this->assertEquals(7.5, (float) $package->price_usdt);
    }

    public function test_admin_can_manage_product_features(): void
    {
        [$admin, $product] = $this->makeCatalogProduct();

        $response = $this->actingAs($admin)->post(route('admin.products.features.store', $product), [
            'feature_name' => 'Setup guide included',
        ]);

        $response->assertRedirect(route('admin.products.edit', $product));
        $this->assertDatabaseHas('features', [
            'product_id' => $product->id,
            'name' => 'Setup guide included',
        ]);

        $feature = Feature::where('product_id', $product->id)->firstOrFail();

        $response = $this->actingAs($admin)->patch(route('admin.features.update', $feature), [
            'feature_name' => 'Priority setup guidance',
        ]);

        $response->assertRedirect(route('admin.products.edit', $product));
        $this->assertDatabaseHas('features', [
            'product_id' => $product->id,
            'name' => 'Priority setup guidance',
        ]);

        $feature->refresh();
        $response = $this->actingAs($admin)->delete(route('admin.features.destroy', $feature));

        $response->assertRedirect(route('admin.products.edit', $product));
        $this->assertDatabaseMissing('features', [
            'id' => $feature->id,
        ]);
    }

    public function test_admin_cannot_delete_product_with_order_or_stock_history(): void
    {
        [$admin, $product, $package] = $this->makeCatalogProduct();
        $buyer = User::factory()->create(['email' => 'buyer@example.com']);

        LicenseStock::create([
            'product_id' => $product->id,
            'package_id' => $package->id,
            'license_key' => 'LOCKED-STOCK-KEY',
            'is_sold' => false,
        ]);

        Order::create([
            'order_id' => 'ORDER-CATALOG-LOCK',
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'status' => 'pending',
            'payment_method' => 'pakasir',
            'price' => 10000,
        ]);

        $response = $this->actingAs($admin)->delete(route('admin.products.destroy', $product));

        $response->assertSessionHasErrors('product');
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    private function makeCatalogProduct(): array
    {
        config(['admin.emails' => ['admin@example.com']]);

        $admin = User::factory()->create([
            'email' => 'admin@example.com',
        ]);

        $category = Category::create([
            'name' => 'PC',
            'slug' => 'pc',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'slug' => 'test-product',
            'description' => 'Test product description.',
        ]);

        $package = Package::create([
            'product_id' => $product->id,
            'name' => '1 Day',
            'price' => 10000,
            'price_usdt' => 1.25,
        ]);

        return [$admin, $product, $package, $category];
    }
}
