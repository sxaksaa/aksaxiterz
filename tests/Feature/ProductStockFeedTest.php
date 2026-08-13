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
            ->whereHas('product', fn ($query) => $query->visible()->ready())
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
            ->whereHas('product', fn ($query) => $query->visible()->ready())
            ->count();
        $category = Category::create(['name' => 'Android', 'slug' => 'stock-feed-android']);
        [$product, $package] = $this->productWithPackage($category, 'Restock Product', 'restock-product');
        $stock = $this->stock($product, $package, 'RESTOCK-SENSITIVE-KEY');
        [$updatingProduct, $updatingPackage] = $this->productWithPackage(
            $category,
            'Updating Stock Product',
            'updating-stock-product'
        );
        $updatingProduct->update(['status' => Product::STATUS_UPDATING]);
        $this->stock($updatingProduct, $updatingPackage, 'UPDATING-SENSITIVE-KEY-1');
        $this->stock($updatingProduct, $updatingPackage, 'UPDATING-SENSITIVE-KEY-2');

        $this->getJson(route('products.stocks'))
            ->assertOk()
            ->assertJsonPath('total_available_stock', $existingVisibleStock + 1)
            ->assertJsonFragment([
                'id' => $product->id,
                'available_stock' => 1,
            ])
            ->assertJsonFragment([
                'id' => $updatingProduct->id,
                'status' => Product::STATUS_UPDATING,
                'available_stock' => 2,
            ])
            ->assertDontSee('RESTOCK-SENSITIVE-KEY');

        $this->get('/')
            ->assertOk()
            ->assertSee(
                '<strong data-home-count-up="'.($existingVisibleStock + 1).'" data-total-ready-stock>'.($existingVisibleStock + 1).'</strong>',
                false,
            )
            ->assertSee('available licenses')
            ->assertSee('available · Auto delivery')
            ->assertSee('product-status-badge-static hidden', false);

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
            ->assertSee(
                '<strong data-home-count-up="'.$existingVisibleStock.'" data-total-ready-stock>'.$existingVisibleStock.'</strong>',
                false,
            )
            ->assertSee('data-total-ready-stock', false)
            ->assertSee('data-product-stock-card', false)
            ->assertSee('data-product-stock-label', false)
            ->assertSee('productStockEndpoint', false);
    }

    public function test_product_detail_feed_reports_package_stock_with_available_semantics_without_leaking_keys(): void
    {
        $user = User::factory()->create();
        $category = Category::create(['name' => 'Detail Feed', 'slug' => 'detail-stock-feed']);
        [$product, $package] = $this->productWithPackage(
            $category,
            'Detail Stock Product',
            'detail-stock-product'
        );
        $secondPackage = Package::create([
            'product_id' => $product->id,
            'name' => '90 Days',
            'price' => 50000,
            'price_usdt' => 3.25,
        ]);

        $this->stock($product, $package, 'DETAIL-AVAILABLE-KEY');
        $this->stock($product, $package, 'DETAIL-SOLD-KEY', ['is_sold' => true]);

        $activeOrder = $this->order($user, $product, $package, 'DETAIL-ACTIVE-ORDER');
        $this->stock($product, $package, 'DETAIL-ACTIVE-RESERVED-KEY', [
            'reserved_order_id' => $activeOrder->id,
            'reserved_until' => now()->addHour(),
        ]);

        $cancelledOrder = $this->order($user, $product, $package, 'DETAIL-CANCELLED-ORDER', [
            'status' => 'cancelled',
        ]);
        $this->stock($product, $package, 'DETAIL-CANCELLED-RESERVED-KEY', [
            'reserved_order_id' => $cancelledOrder->id,
            'reserved_until' => now()->addHour(),
        ]);

        $expiredCryptoOrder = $this->order($user, $product, $package, 'DETAIL-EXPIRED-CRYPTO-ORDER');
        $this->stock($product, $package, 'DETAIL-EXPIRED-CRYPTO-KEY', [
            'reserved_order_id' => $expiredCryptoOrder->id,
            'reserved_until' => now()->subMinute(),
        ]);

        $expiredQrisOrder = $this->order($user, $product, $package, 'DETAIL-EXPIRED-QRIS-ORDER', [
            'payment_method' => 'pakasir',
        ]);
        $this->stock($product, $package, 'DETAIL-EXPIRED-QRIS-KEY', [
            'reserved_order_id' => $expiredQrisOrder->id,
            'reserved_until' => now()->subMinute(),
        ]);

        $this->stock($product, $secondPackage, 'DETAIL-SECOND-PACKAGE-KEY');

        $response = $this->getJson(route('products.stock-detail', ['product' => $product->slug]));

        $response
            ->assertOk()
            ->assertJsonPath('id', $product->id)
            ->assertJsonPath('status', Product::STATUS_READY)
            ->assertJsonPath('status_label', 'Ready')
            ->assertJsonPath('available_stock', 4)
            ->assertJsonCount(2, 'packages')
            ->assertJsonPath('packages.0.id', $package->id)
            ->assertJsonPath('packages.0.available_stock', 3)
            ->assertJsonPath('packages.1.id', $secondPackage->id)
            ->assertJsonPath('packages.1.available_stock', 1);

        $rootKeys = array_keys($response->json());
        sort($rootKeys);
        $this->assertSame([
            'available_stock',
            'id',
            'packages',
            'status',
            'status_label',
        ], $rootKeys);

        foreach ($response->json('packages') as $packagePayload) {
            $packageKeys = array_keys($packagePayload);
            sort($packageKeys);
            $this->assertSame(['available_stock', 'id'], $packageKeys);
        }

        foreach ([
            'DETAIL-AVAILABLE-KEY',
            'DETAIL-SOLD-KEY',
            'DETAIL-ACTIVE-RESERVED-KEY',
            'DETAIL-CANCELLED-RESERVED-KEY',
            'DETAIL-EXPIRED-CRYPTO-KEY',
            'DETAIL-EXPIRED-QRIS-KEY',
            'DETAIL-SECOND-PACKAGE-KEY',
            'DETAIL-ACTIVE-ORDER',
            'DETAIL-CANCELLED-ORDER',
            'DETAIL-EXPIRED-CRYPTO-ORDER',
            'DETAIL-EXPIRED-QRIS-ORDER',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=0', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
    }

    public function test_product_detail_feed_reflects_stock_and_status_changes_on_the_next_request(): void
    {
        $category = Category::create(['name' => 'Live Detail', 'slug' => 'live-detail-stock']);
        [$product, $package] = $this->productWithPackage(
            $category,
            'Live Detail Product',
            'live-detail-product'
        );
        $this->stock($product, $package, 'LIVE-DETAIL-INITIAL-KEY');
        $endpoint = route('products.stock-detail', ['product' => $product->slug]);

        $this->getJson($endpoint)
            ->assertOk()
            ->assertJsonPath('status', Product::STATUS_READY)
            ->assertJsonPath('status_label', 'Ready')
            ->assertJsonPath('available_stock', 1)
            ->assertJsonPath('packages.0.available_stock', 1);

        $this->stock($product, $package, 'LIVE-DETAIL-NEW-KEY');
        $product->update(['status' => Product::STATUS_UPDATING]);

        $this->getJson($endpoint)
            ->assertOk()
            ->assertJsonPath('status', Product::STATUS_UPDATING)
            ->assertJsonPath('status_label', 'Updating')
            ->assertJsonPath('available_stock', 2)
            ->assertJsonPath('packages.0.available_stock', 2)
            ->assertDontSee('LIVE-DETAIL-INITIAL-KEY')
            ->assertDontSee('LIVE-DETAIL-NEW-KEY');
    }

    public function test_product_detail_feed_rejects_hidden_and_unknown_products(): void
    {
        $category = Category::create(['name' => 'Private Detail', 'slug' => 'private-detail-stock']);
        [$hiddenProduct] = $this->productWithPackage(
            $category,
            'Hidden Detail Product',
            'hidden-detail-product',
            false
        );

        $this->getJson(route('products.stock-detail', ['product' => $hiddenProduct->slug]))
            ->assertNotFound();

        $this->getJson(route('products.stock-detail', ['product' => 'unknown-detail-product']))
            ->assertNotFound();
    }

    public function test_product_detail_renders_live_stock_endpoint_and_package_targets(): void
    {
        $category = Category::create(['name' => 'Detail Markers', 'slug' => 'detail-stock-markers']);
        [$product, $package] = $this->productWithPackage(
            $category,
            'Detail Marker Product',
            'detail-marker-product'
        );
        $this->stock($product, $package, 'DETAIL-MARKER-KEY-1');
        $this->stock($product, $package, 'DETAIL-MARKER-KEY-2');

        $response = $this->get(route('products.show', $product));

        $response
            ->assertOk()
            ->assertSee('data-product-checkout-ready="true"', false)
            ->assertSee(
                'data-product-stock-endpoint="'.route(
                    'products.stock-detail',
                    ['product' => $product->slug],
                    false
                ).'"',
                false
            )
            ->assertSee('data-product-status-badge', false)
            ->assertSee('product-status-badge-static hidden', false)
            ->assertDontSee('Starts from')
            ->assertDontSee('data-product-availability-value', false)
            ->assertSee('data-package-card', false)
            ->assertSee('packageMemoryKey', false)
            ->assertSee('package-stock-changed', false)
            ->assertSee('data-package-id="'.$package->id.'"', false)
            ->assertSee('data-stock="2"', false)
            ->assertSee('data-package-checkout-enabled="true"', false)
            ->assertSee('data-package-availability', false)
            ->assertSee('2 available · Auto delivery')
            ->assertSee('data-manual-order', false)
            ->assertSee('data-manual-order-label', false)
            ->assertSee('id="addToCartBtn"', false)
            ->assertSee('id="buyNowBtn"', false)
            ->assertDontSee('id="payMainBtn"', false)
            ->assertDontSee('DETAIL-MARKER-KEY-1')
            ->assertDontSee('DETAIL-MARKER-KEY-2');

    }

    public function test_home_and_fragment_list_ready_products_before_updating_products(): void
    {
        $category = Category::create(['name' => 'Ordering', 'slug' => 'stock-feed-ordering']);
        [$readyProduct] = $this->productWithPackage(
            $category,
            'Zulu Ready Without Stock',
            'zulu-ready-without-stock'
        );
        [$updatingProduct, $updatingPackage] = $this->productWithPackage(
            $category,
            'Aardvark Updating With Stock',
            'aardvark-updating-with-stock'
        );
        $updatingProduct->update(['status' => Product::STATUS_UPDATING]);
        $this->stock($updatingProduct, $updatingPackage, 'UPDATING-ORDER-SENSITIVE-KEY');

        foreach (['/', route('products.fragment')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSeeInOrder([
                    'data-product-id="'.$readyProduct->id.'"',
                    'data-product-id="'.$updatingProduct->id.'"',
                ], false)
                ->assertDontSee('UPDATING-ORDER-SENSITIVE-KEY');
        }
    }

    public function test_product_search_ignores_spaces_and_punctuation_in_product_names(): void
    {
        $category = Category::create(['name' => 'Search', 'slug' => 'stock-feed-search']);
        [$hyphenatedProduct] = $this->productWithPackage($category, 'Aurora-VN', 'aurora-vn-search');
        [$spacedProduct] = $this->productWithPackage($category, 'XG Team', 'xg-team-search');
        [$unrelatedProduct] = $this->productWithPackage($category, 'Unrelated Product', 'unrelated-search');

        $this->get('/?search=Aurora%20VN')
            ->assertOk()
            ->assertSee($hyphenatedProduct->name)
            ->assertDontSee($spacedProduct->name)
            ->assertDontSee($unrelatedProduct->name);

        $this->get(route('products.fragment', ['search' => 'XG-Team']))
            ->assertOk()
            ->assertSee($spacedProduct->name)
            ->assertDontSee($hyphenatedProduct->name)
            ->assertDontSee($unrelatedProduct->name);
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
