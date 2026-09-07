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

    public function test_catalog_quick_edit_updates_details_prices_and_visibility_together(): void
    {
        [$admin, $product, $package, $category] = $this->makeCatalogProduct();
        $buyer = User::factory()->create();
        CartItem::create(['user_id' => $buyer->id, 'product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]);
        $catalog = route('admin.products.index', ['search' => 'Test', 'visibility' => 'visible']);
        $this->actingAs($admin)->get($catalog)->assertOk()->assertSee('data-catalog-edit-button', false)->assertSee('packages['.$package->id.'][price]', false);

        $this->patch(route('admin.products.quick-update', ['product' => $product, 'search' => 'Test', 'visibility' => 'visible']), [
            'name' => $product->name,
            'category_id' => $category->id,
            'description' => 'Changed in catalog.',
            'status' => Product::STATUS_UPDATING,
            'is_visible' => '0',
            'packages' => [$package->id => ['id' => $package->id, 'price' => 25000, 'price_usdt' => '']],
        ])->assertRedirect($catalog)->assertSessionHasNoErrors();

        $this->assertFalse($product->fresh()->is_visible);
        $this->assertSame(Product::STATUS_UPDATING, $product->fresh()->status);
        $this->assertSame('Changed in catalog.', $product->fresh()->description);
        $this->assertSame(25000, $package->fresh()->price);
        $this->assertNull($package->fresh()->price_usdt);
        $this->assertDatabaseMissing('cart_items', ['product_id' => $product->id]);
    }

    public function test_quick_edit_rejects_invalid_prices_and_foreign_packages_without_partial_updates(): void
    {
        [$admin, $product, $package, $category] = $this->makeCatalogProduct();
        $other = Product::create(['name' => 'Other', 'slug' => 'other', 'category_id' => $category->id, 'description' => 'Other product']);
        $foreign = $other->packages()->create(['name' => '1 Day', 'price' => 500]);
        $payload = [
            'quick_product_id' => $product->id,
            'name' => $product->name,
            'category_id' => $category->id,
            'description' => 'Must not be saved.',
            'status' => Product::STATUS_UPDATING,
            'is_visible' => '0',
            'packages' => [$package->id => ['id' => $package->id, 'price' => -1]],
        ];
        $catalog = route('admin.products.index');
        $this->actingAs($admin)->from($catalog)->patch(route('admin.products.quick-update', $product), $payload)
            ->assertSessionHasErrors('packages.'.$package->id.'.price');
        $this->get($catalog)->assertOk()->assertSee('Must not be saved.');
        $payload['packages'] = [['id' => $foreign->id, 'price' => 100]];
        $this->patch(route('admin.products.quick-update', $product), $payload)->assertSessionHasErrors('packages.0.id');
        $this->assertSame('Test product description.', $product->fresh()->description);
        $this->assertTrue($product->fresh()->is_visible);
        $this->assertSame(10000, $package->fresh()->price);
        $this->assertSame(500, $foreign->fresh()->price);
    }

    public function test_non_admin_cannot_quick_edit_catalog(): void
    {
        [, $product] = $this->makeCatalogProduct();
        $this->actingAs(User::factory()->create())
            ->patch(route('admin.products.quick-update', $product), ['is_visible' => '0'])
            ->assertNotFound();
        $this->assertTrue($product->fresh()->is_visible);
    }

    public function test_catalog_can_manage_notes_and_package_durations(): void
    {
        [$admin, $product, $package, $category] = $this->makeCatalogProduct();
        $this->actingAs($admin)->get(route('admin.products.index'))
            ->assertOk()->assertSee('Important note')->assertSee('Add package')->assertSee('Delete product')->assertDontSee('Full edit');
        $payload = [
            'name' => $product->name, 'category_id' => $category->id,
            'description' => $product->description, 'status' => 'ready', 'is_visible' => '1',
            'important_note' => 'Read before purchasing.',
            'packages' => [['id' => $package->id, 'name' => '7 Days', 'price' => 50000]],
        ];
        $this->patch(route('admin.products.quick-update', $product), $payload)->assertSessionHasNoErrors();
        $this->assertSame('7 Days', $package->fresh()->name);
        $this->assertSame('Read before purchasing.', $product->fresh()->important_note);
        $payload['important_note'] = '';
        $this->patch(route('admin.products.quick-update', $product), $payload)->assertSessionHasNoErrors();
        $this->assertNull($product->fresh()->important_note);
        $payload['packages'][0]['name'] = 'Invalid duration';
        $this->patch(route('admin.products.quick-update', $product), $payload)->assertSessionHasErrors('packages.0.name');
        $this->assertSame('7 Days', $package->fresh()->name);
    }

    public function test_catalog_package_actions_keep_filters_and_protect_stock(): void
    {
        [$admin, $product, $package] = $this->makeCatalogProduct();
        $query = ['search' => 'Test', 'visibility' => 'visible'];
        $catalog = route('admin.products.index', $query);
        $this->actingAs($admin)->from($catalog)->post(route('admin.products.packages.store', ['product' => $product, ...$query]), [
            'from_catalog' => '1', 'package_name' => '3 Days', 'package_price' => 30000,
        ])->assertRedirect($catalog)->assertSessionHasNoErrors();
        $added = $product->packages()->where('name', '3 Days')->firstOrFail();
        $this->delete(route('admin.packages.destroy', ['package' => $added, ...$query]), ['from_catalog' => '1'])
            ->assertRedirect($catalog)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('packages', ['id' => $added->id]);
        LicenseStock::create(['product_id' => $product->id, 'package_id' => $package->id, 'license_key' => 'CATALOG-LOCKED-KEY', 'is_sold' => false]);
        $this->delete(route('admin.packages.destroy', ['package' => $package, ...$query]), ['from_catalog' => '1'])
            ->assertRedirect($catalog)->assertSessionHasErrors('package');
        $this->assertDatabaseHas('packages', ['id' => $package->id]);
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
