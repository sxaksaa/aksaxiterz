<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Category;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Services\PaymentService;
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

    public function test_admin_can_manage_optional_product_important_note(): void
    {
        [$admin, $product] = $this->makeCatalogProduct();

        $response = $this->actingAs($admin)->patch(route('admin.products.important-note.update', $product), [
            'important_note' => 'Please turn off Discord activity while using this product.',
        ]);

        $response->assertRedirect(route('admin.products.edit', $product));
        $this->assertSame(
            'Please turn off Discord activity while using this product.',
            $product->fresh()->important_note
        );
        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('Important Note')
            ->assertSee('Please turn off Discord activity while using this product.');

        $response = $this->actingAs($admin)->patch(route('admin.products.important-note.update', $product), [
            'important_note' => '',
        ]);

        $response->assertRedirect(route('admin.products.edit', $product));
        $this->assertNull($product->fresh()->important_note);
        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertDontSee('Important Note');
    }

    public function test_admin_can_hide_product_from_storefront_and_purchase_paths(): void
    {
        [$admin, $product, $package, $category] = $this->makeCatalogProduct();
        $buyer = User::factory()->create();

        LicenseStock::create([
            'product_id' => $product->id,
            'package_id' => $package->id,
            'license_key' => 'HIDDEN-PRODUCT-STOCK',
            'is_sold' => false,
        ]);

        CartItem::create([
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($admin)->patch(route('admin.products.update', $product), [
            'category_id' => $category->id,
            'name' => $product->name,
            'description' => $product->description,
            'status' => Product::STATUS_READY,
            'is_visible' => '0',
        ]);

        $response->assertRedirect(route('admin.products.edit', $product));
        $this->assertFalse($product->fresh()->is_visible);
        $this->assertDatabaseMissing('cart_items', ['user_id' => $buyer->id, 'product_id' => $product->id]);

        $this->get('/')->assertOk()->assertDontSee($product->name);
        $this->get(route('products.fragment'))->assertOk()->assertDontSee($product->name);
        $this->get(route('products.show', $product))->assertNotFound();

        $this->actingAs($buyer)
            ->postJson(route('cart.items.store', $product), [
                'package_id' => $package->id,
                'quantity' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This product is not available for purchase.');

        $this->postJson(route('vouchers.preview'), [
            'code' => 'HIDDEN10',
            'package_id' => $package->id,
            'payment_method' => 'gopay_qris',
            'quantity' => 1,
        ])->assertNotFound();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('This product is not available for purchase.');

        app(PaymentService::class)->createGopayQrisPayment($buyer, $product->id, $package->id);
    }

    public function test_hidden_product_cannot_receive_new_stock_but_remains_available_in_stock_management(): void
    {
        [$admin, $product, $package] = $this->makeCatalogProduct();

        $product->update(['is_visible' => false]);

        $stockPage = $this->actingAs($admin)->get(route('admin.license-stocks.index'));

        $stockPage
            ->assertOk()
            ->assertSee($product->name)
            ->assertDontSee('data-add-stock-product="'.$product->id.'"', false)
            ->assertDontSee('data-add-stock-package="'.$package->id.'"', false);

        $this->actingAs($admin)
            ->from(route('admin.license-stocks.index'))
            ->post(route('admin.license-stocks.store'), [
                'product_id' => $product->id,
                'package_id' => $package->id,
                'license_keys' => 'HIDDEN-PRODUCT-NEW-STOCK',
            ])
            ->assertRedirect(route('admin.license-stocks.index'))
            ->assertSessionHasErrors([
                'product_id' => 'Hidden products cannot receive new stock. Make the product public first.',
            ]);

        $this->assertDatabaseMissing('license_stocks', [
            'license_key' => 'HIDDEN-PRODUCT-NEW-STOCK',
        ]);
    }

    public function test_license_stock_page_defaults_to_available_and_can_still_show_all_statuses(): void
    {
        [$admin, $product, $package] = $this->makeCatalogProduct();

        LicenseStock::create([
            'product_id' => $product->id,
            'package_id' => $package->id,
            'license_key' => 'AVAILABLE-STOCK-KEY',
            'is_sold' => false,
        ]);
        LicenseStock::create([
            'product_id' => $product->id,
            'package_id' => $package->id,
            'license_key' => 'SOLD-STOCK-KEY',
            'is_sold' => true,
            'sold_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.license-stocks.index'))
            ->assertOk()
            ->assertSee('AVAILABLE-STOCK-KEY')
            ->assertDontSee('SOLD-STOCK-KEY')
            ->assertSee('<option value="available" selected>Available</option>', false);

        $this->actingAs($admin)
            ->get(route('admin.license-stocks.index', ['status' => '']))
            ->assertOk()
            ->assertSee('AVAILABLE-STOCK-KEY')
            ->assertSee('SOLD-STOCK-KEY')
            ->assertSee('<option value="" selected>All status</option>', false);
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

        // Historical Pakasir orders must continue protecting their catalog records.
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

        $category = Category::firstOrCreate(
            ['slug' => 'pc'],
            ['name' => 'PC']
        );

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
