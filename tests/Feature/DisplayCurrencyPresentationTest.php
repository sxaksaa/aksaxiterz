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

class DisplayCurrencyPresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for display currency tests.');
        }

        parent::setUp();
    }

    public function test_storefront_exposes_accessible_idr_usd_prices_with_separate_minimum_packages(): void
    {
        [, $product, $idrPackage] = $this->catalogItem(
            name: 'Currency Catalog',
            packageName: '1 Day',
            priceIdr: 10_000,
            priceUsd: 2,
            stock: 1
        );
        $usdPackage = Package::create([
            'product_id' => $product->id,
            'name' => '7 Days',
            'price' => 20_000,
            'price_usdt' => 1,
        ]);
        $this->stock($product, $usdPackage, 'CURRENCY-USD-KEY');

        $home = $this->withHeader('CF-IPCountry', 'US')
            ->get('/')
            ->assertOk()
            ->assertSee('data-display-currency="idr"', false)
            ->assertSee('data-visitor-country="US"', false)
            ->assertSee('data-currency-prepaint', false)
            ->assertSee('aria-label="Display currency"', false)
            ->assertSee('data-currency-option="idr"', false)
            ->assertSee('data-currency-option="usd"', false)
            ->assertSee('data-price-idr="10000"', false)
            ->assertSee('data-price-usd="1"', false)
            ->assertSee('data-currency-text-idr="From 1 day"', false)
            ->assertSee('data-currency-text-usd="From 7 days"', false);

        $this->assertSame(2, substr_count($home->getContent(), 'data-currency-switcher'));

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('data-price-idr="10000"', false)
            ->assertSee('data-price-usd="1"', false)
            ->assertSee('data-currency-text-idr="1 day access"', false)
            ->assertSee('data-currency-text-usd="7 days access"', false)
            ->assertSee('data-price-usdt="2"', false)
            ->assertSee('data-price-usdt="1"', false);

        $this->get(route('guides.index'))
            ->assertOk()
            ->assertDontSee('data-currency-switcher', false);

        $this->assertSame($idrPackage->id, $product->packages()->orderBy('price')->value('id'));
        $this->assertSame($usdPackage->id, $product->packages()->orderBy('price_usdt')->value('id'));
    }

    public function test_display_currency_fields_cannot_change_qris_invoice_or_order_item_prices(): void
    {
        [$user, $product, $package] = $this->catalogItem(
            name: 'Server Price Guard',
            packageName: '30 Days',
            priceIdr: 20_000,
            priceUsd: 1.25,
            stock: 2
        );
        $this->enableGopayQris();

        $checkout = $this->actingAs($user)
            ->get(route('checkout.product', [
                'product' => $product,
                'package' => $package->id,
                'quantity' => 2,
            ]))
            ->assertOk()
            ->assertSee('data-price-idr="40000"', false)
            ->assertSee('data-price-usd="2.5"', false)
            ->assertSee('Final payment is charged in IDR through QRIS.')
            ->assertSee('Final payment uses ${String(paymentToken).toUpperCase()}')
            ->assertDontSee('name="display_currency"', false);

        $this->assertDoesNotMatchRegularExpression(
            '/id="checkoutTotal"[^>]*data-display-price/',
            $checkout->getContent()
        );

        $response = $this->actingAs($user)
            ->postJson(route('checkout.product.process', $product), [
                'payment_method' => 'gopay_qris',
                'package_id' => $package->id,
                'quantity' => 2,
                'display_currency' => 'usd',
                'currency' => 'USD',
                'price' => 1,
                'price_usdt' => 0.01,
                'exchange_rate' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('method', 'gopay_qris');

        $order = Order::where('order_id', $response->json('order_id'))->firstOrFail();

        $this->assertSame(40_000, (int) $order->payment_payload['base_amount']);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'package_id' => $package->id,
            'quantity' => 2,
            'unit_price_idr' => 20_000,
            'unit_price_usdt' => 1.25,
            'line_total_idr' => 40_000,
            'line_total_usdt' => 2.5,
        ]);
    }

    public function test_missing_usd_price_disables_stablecoin_checkout_and_fails_closed_on_post(): void
    {
        [$user, $product, $package] = $this->catalogItem(
            name: 'IDR Only Package',
            packageName: '1 Day',
            priceIdr: 15_000,
            priceUsd: null,
            stock: 1
        );
        CartItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]);
        $this->enableGopayQris();
        config([
            'services.crypto_direct.networks.usdtbsc.address' => '0x1111111111111111111111111111111111111111',
            'services.crypto_direct.networks.usdtbsc.contract' => '0x2222222222222222222222222222222222222222',
            'services.crypto_direct.networks.usdtbsc.rpc_url' => 'https://rpc.invalid.test',
        ]);

        $this->actingAs($user)
            ->get(route('cart.index'))
            ->assertOk()
            ->assertSee('data-price-usd=""', false);

        $checkout = $this->actingAs($user)
            ->get(route('checkout.cart'))
            ->assertOk()
            ->assertSee('USD price unavailable for this selection')
            ->assertSee('data-price-usd=""', false);

        $this->assertMatchesRegularExpression(
            '/name="payment_method"\s+value="crypto"[^>]*disabled/s',
            $checkout->getContent()
        );
        $this->assertDoesNotMatchRegularExpression(
            '/name="payment_method"\s+value="gopay_qris"[^>]*disabled/s',
            $checkout->getContent()
        );

        $this->actingAs($user)
            ->postJson(route('cart.checkout'), [
                'payment_method' => 'crypto',
                'coin' => 'usdtbsc',
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'USD payment is unavailable for one or more selected packages.'
            );

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('cart_items', [
            'user_id' => $user->id,
            'package_id' => $package->id,
        ]);
    }

    private function catalogItem(
        string $name,
        string $packageName,
        int $priceIdr,
        ?float $priceUsd,
        int $stock
    ): array {
        $user = User::factory()->create();
        $suffix = strtolower(str_replace(' ', '-', $name)).'-'.uniqid();
        $category = Category::create([
            'name' => 'PC',
            'slug' => 'currency-category-'.$suffix,
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => 'currency-product-'.$suffix,
            'status' => Product::STATUS_READY,
            'is_visible' => true,
            'description' => 'Display currency test product.',
        ]);
        $package = Package::create([
            'product_id' => $product->id,
            'name' => $packageName,
            'price' => $priceIdr,
            'price_usdt' => $priceUsd,
        ]);

        for ($index = 1; $index <= $stock; $index++) {
            $this->stock($product, $package, "CURRENCY-{$index}-".uniqid());
        }

        return [$user, $product, $package];
    }

    private function stock(Product $product, Package $package, string $key): void
    {
        LicenseStock::create([
            'product_id' => $product->id,
            'package_id' => $package->id,
            'license_key' => $key,
            'is_sold' => false,
        ]);
    }

    private function enableGopayQris(): void
    {
        config([
            'services.gopay_qris.enabled' => true,
            'services.gopay_qris.static_payload' => '00020101021126610014COM.GO-JEK.WWW01189360091438659284520210G8659284520303UMI51440014ID.CO.QRIS.WWW0215ID10243297931020303UMI5204729953033605802ID5911Aksa Xiterz6006MALANG61056515362070703A0163045DEF',
            'services.gopay_qris.merchant_name' => 'Aksa Xiterz',
            'services.gopay_qris.merchant_reference' => 'ID102432979310',
            'services.gopay_qris.expires_minutes' => 10,
            'services.gopay_qris.unique_max' => 999,
            'services.gopay_qris.webhook_token' => 'currency-checkout-token',
            'services.gopay_qris.webhook_secret' => 'currency-checkout-secret',
            'services.gopay_qris.allowed_devices' => ['aksa-gopay-primary'],
        ]);
    }
}
