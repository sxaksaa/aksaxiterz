<?php

namespace Tests\Feature;

use App\Exceptions\VoucherException;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\Voucher;
use App\Services\OrderFulfillmentService;
use App\Services\PendingOrderExpirationService;
use App\Services\StockReservationService;
use App\Services\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

class CartCheckoutFeatureTest extends TestCase
{
    use RefreshDatabase;

    private const STATIC_QRIS = '00020101021126610014COM.GO-JEK.WWW01189360091438659284520210G8659284520303UMI51440014ID.CO.QRIS.WWW0215ID10243297931020303UMI5204729953033605802ID5911Aksa Xiterz6006MALANG61056515362070703A0163045DEF';

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for cart checkout tests.');
        }

        parent::setUp();
    }

    public function test_customer_can_build_persistent_custom_bundle(): void
    {
        [$user, $firstProduct, $firstPackage] = $this->catalogItem('Aurora', 20000, 1.25, 3);
        [, $secondProduct, $secondPackage] = $this->catalogItem('Drip', 35000, 2.5, 2, $user);

        $this->actingAs($user)
            ->postJson(route('cart.items.store', $firstProduct), [
                'package_id' => $firstPackage->id,
                'quantity' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('cart_count', 2);

        $this->actingAs($user)
            ->postJson(route('cart.items.store', $secondProduct), [
                'package_id' => $secondPackage->id,
                'quantity' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('cart_count', 3);

        $this->assertDatabaseCount('cart_items', 2);
        $this->actingAs($user)
            ->get(route('cart.index'))
            ->assertOk()
            ->assertSee('Aurora')
            ->assertSee('Drip')
            ->assertSee('Custom Bundle')
            ->assertSee('Decrease Aurora 30 Days quantity')
            ->assertSee('Increase Aurora 30 Days quantity')
            ->assertSee('Continue to Checkout')
            ->assertDontSee('name="payment_method"', false)
            ->assertDontSee('id="checkoutVoucherCode"', false)
            ->assertDontSee('>Update<', false);
    }

    public function test_customer_can_load_a_checkout_ready_mini_cart_preview(): void
    {
        [$user, $product, $package] = $this->catalogItem('Aurora Preview', 20000, 1.25, 3);
        CartItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 2,
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('cart.preview'))
            ->assertOk()
            ->assertJsonPath('cart_count', 2);

        $html = $response->json('html');
        $this->assertStringContainsString('Aurora Preview', $html);
        $this->assertMatchesRegularExpression('/2\s+licenses/', $html);
        $this->assertStringContainsString('data-price-idr="40000"', $html);
        $this->assertStringContainsString('href="'.route('checkout.cart').'"', $html);
        $this->assertStringContainsString('Edit Cart', $html);
        $this->assertStringContainsString('Checkout', $html);
        $this->assertSame(1, substr_count($html, route('cart.index')));
    }

    public function test_mini_cart_sends_unavailable_items_to_cart_review(): void
    {
        [$user, $product, $package] = $this->catalogItem('Paused Preview', 20000, 1.25, 1);
        CartItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]);
        $product->update(['status' => Product::STATUS_UPDATING]);

        $html = $this->actingAs($user)
            ->getJson(route('cart.preview'))
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('Review Cart', $html);
        $this->assertStringNotContainsString('href="'.route('checkout.cart').'"', $html);
        $this->assertSame(1, substr_count($html, route('cart.index')));
    }

    public function test_cart_page_disables_checkout_when_an_item_changes_to_updating(): void
    {
        $this->enableGopayQris();
        [$user, $product, $package] = $this->catalogItem('Paused Product', 20000, 1.25, 1);
        CartItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]);
        $product->update(['status' => Product::STATUS_UPDATING]);

        $response = $this->actingAs($user)
            ->get(route('cart.index'))
            ->assertOk()
            ->assertSee('data-cart-checkout-paused', false)
            ->assertSee('data-cart-item-checkout-ready="false"', false)
            ->assertSee('This selection needs to be reviewed')
            ->assertSee('Review Unavailable Items')
            ->assertDontSee('Continue to Checkout')
            ->assertDontSee('href="'.route('checkout.cart').'"', false);

        $this->assertMatchesRegularExpression(
            '/<button[^>]*disabled[^>]*>.*Review Unavailable Items.*<\/button>/s',
            $response->getContent()
        );
    }

    public function test_cart_checkout_creates_one_discounted_invoice_and_delivers_every_item(): void
    {
        $this->enableGopayQris();

        [$user, $firstProduct, $firstPackage] = $this->catalogItem('Aurora', 50000, 3, 2);
        [, $secondProduct, $secondPackage] = $this->catalogItem('Drip', 100000, 6, 1, $user);
        $voucher = Voucher::create([
            'code' => 'BUNDLE10',
            'discount_percent' => 10,
            'max_discount' => 20000,
            'max_discount_usdt' => 1,
            'max_discount_usdc' => 1,
            'minimum_purchase' => 0,
            'usage_limit' => 10,
            'per_user_limit' => 0,
            'is_active' => true,
        ]);
        CartItem::create([
            'user_id' => $user->id,
            'product_id' => $firstProduct->id,
            'package_id' => $firstPackage->id,
            'quantity' => 2,
        ]);
        CartItem::create([
            'user_id' => $user->id,
            'product_id' => $secondProduct->id,
            'package_id' => $secondPackage->id,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('cart.checkout'), [
                'payment_method' => 'gopay_qris',
                'voucher_code' => $voucher->code,
                'price' => 1,
                'discount_idr' => 999999999,
                'product_id' => $secondProduct->id,
            ])
            ->assertOk()
            ->assertJsonPath('method', 'gopay_qris')
            ->assertJsonPath('quantity', 3);

        $order = Order::where('order_id', $response->json('order_id'))->firstOrFail();
        $qrisPayment = $order->payment_payload;
        $this->assertSame(2, $order->items()->count());
        $this->assertSame(3, $order->quantity);
        $this->assertSame('gopay_qris', $order->payment_method);
        $this->assertSame(180000, (int) $qrisPayment['base_amount']);
        $this->assertSame((int) ceil(180000 / 0.993) - 180000, (int) $qrisPayment['platform_fee']);
        $this->assertGreaterThanOrEqual(1, (int) $qrisPayment['unique_amount']);
        $this->assertLessThanOrEqual(999, (int) $qrisPayment['unique_amount']);
        $this->assertSame((int) $order->price, (int) $qrisPayment['total_payment']);
        $this->assertSame(self::STATIC_QRIS, $qrisPayment['qr_payload']);
        $this->assertSame($voucher->id, $order->voucher_id);
        $this->assertSame(3, LicenseStock::where('reserved_order_id', $order->id)->count());
        $this->assertSame(0, CartItem::where('user_id', $user->id)->count());

        app(OrderFulfillmentService::class)->fulfill($order);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(3, $order->licenses()->count());
        $this->assertSame(2, $order->licenses()->where('product_id', $firstProduct->id)->count());
        $this->assertSame(1, $order->licenses()->where('product_id', $secondProduct->id)->count());

        $this->actingAs($user)
            ->get('/orders')
            ->assertOk()
            ->assertSee('Aurora')
            ->assertSee('Drip');
        $licenseResponse = $this->actingAs($user)
            ->get('/licenses?order='.$order->order_id)
            ->assertOk()
            ->assertSee('Aurora')
            ->assertSee('Drip')
            ->assertSee('2 licenses · latest purchase')
            ->assertSee('1 license · latest purchase')
            ->assertSee('Copy 2 Keys');

        $licenseHtml = $licenseResponse->getContent();
        $this->assertSame(1, substr_count($licenseHtml, 'id="license-'.$order->order_id.'"'));
        $this->assertSame(3, substr_count($licenseHtml, 'data-copy-license='));

        config(['admin.emails' => [$user->email]]);
        $this->actingAs($user)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('Aurora')
            ->assertSee('Drip');
    }

    public function test_bundle_voucher_cap_applies_to_each_license(): void
    {
        [$user, $firstProduct, $firstPackage] = $this->catalogItem('Aurora', 200000, 10, 1);
        [, $secondProduct, $secondPackage] = $this->catalogItem('Drip', 200000, 10, 1, $user);
        $voucher = Voucher::create([
            'code' => 'PERPRODUCT',
            'discount_percent' => 10,
            'max_discount' => 15000,
            'max_discount_usdt' => 0.5,
            'max_discount_usdc' => 0.75,
            'minimum_purchase' => 0,
            'usage_limit' => 10,
            'per_user_limit' => 0,
            'is_active' => true,
        ]);

        foreach ([[$firstProduct, $firstPackage], [$secondProduct, $secondPackage]] as [$product, $package]) {
            CartItem::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'package_id' => $package->id,
                'quantity' => 1,
            ]);
        }

        $items = CartItem::with(['product', 'package'])->where('user_id', $user->id)->get();
        $qrisQuote = app(VoucherService::class)->quoteCart($items, $user, $voucher->code);
        $usdcQuote = app(VoucherService::class)->quoteCart(
            $items,
            $user,
            $voucher->code,
            paymentMethod: 'crypto',
            coin: 'usdcbsc'
        );

        $this->assertSame(30000, $qrisQuote['discount_idr']);
        $this->assertSame(370000, $qrisQuote['final_idr']);
        $this->assertSame('per_item', $qrisQuote['discount_cap_scope']);
        $this->assertSame(2, $qrisQuote['discount_units']);
        $this->assertSame(30000, $qrisQuote['max_discount_total']);
        $this->assertSame(1.5, $usdcQuote['discount_usdt']);
        $this->assertSame(18.5, $usdcQuote['final_usdt']);
    }

    public function test_bundle_voucher_requires_selected_products_and_discounts_only_that_bundle(): void
    {
        [$user, $firstProduct, $firstPackage] = $this->catalogItem('Aurora', 100000, 5, 1);
        [, $secondProduct, $secondPackage] = $this->catalogItem('Drip', 100000, 5, 1, $user);
        [, $thirdProduct, $thirdPackage] = $this->catalogItem('Fluorite', 300000, 15, 1, $user);
        $voucher = Voucher::create([
            'code' => 'AURORADRIP',
            'discount_percent' => 10,
            'max_discount' => 15000,
            'max_discount_usdt' => 0.5,
            'max_discount_usdc' => 0.5,
            'minimum_purchase' => 0,
            'usage_limit' => 10,
            'per_user_limit' => 0,
            'required_product_ids' => [$firstProduct->id, $secondProduct->id],
            'is_active' => true,
        ]);

        foreach ([[$firstProduct, $firstPackage], [$thirdProduct, $thirdPackage]] as [$product, $package]) {
            CartItem::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'package_id' => $package->id,
                'quantity' => 1,
            ]);
        }

        $items = CartItem::with(['product', 'package'])->where('user_id', $user->id)->get();

        try {
            app(VoucherService::class)->quoteCart($items, $user, $voucher->code);
            $this->fail('The bundle voucher should reject carts without every required product.');
        } catch (VoucherException $error) {
            $this->assertStringContainsString('selected bundle products', $error->getMessage());
        }

        CartItem::create([
            'user_id' => $user->id,
            'product_id' => $secondProduct->id,
            'package_id' => $secondPackage->id,
            'quantity' => 1,
        ]);

        $items = CartItem::with(['product', 'package'])->where('user_id', $user->id)->get();
        $quote = app(VoucherService::class)->quoteCart($items, $user, $voucher->code);

        $this->assertSame(500000, $quote['base_idr']);
        $this->assertSame(20000, $quote['discount_idr']);
        $this->assertSame(480000, $quote['final_idr']);
        $this->assertSame(2, $quote['discount_units']);
        $this->assertEqualsCanonicalizing([$firstProduct->id, $secondProduct->id], $quote['required_product_ids']);
    }

    public function test_product_voucher_accepts_any_package_duration_from_that_product(): void
    {
        [$user, $product] = $this->catalogItem('BR Mods PC', 35000, 2, 1);
        $tenDayPackage = Package::create([
            'product_id' => $product->id,
            'name' => '10 Days',
            'price' => 100000,
            'price_usdt' => 6,
        ]);
        $voucher = Voucher::create([
            'code' => 'BRMODS',
            'discount_percent' => 10,
            'max_discount' => 10000,
            'max_discount_usdt' => 0.5,
            'max_discount_usdc' => 0.5,
            'minimum_purchase' => 0,
            'usage_limit' => 10,
            'per_user_limit' => 0,
            'required_product_ids' => [$product->id],
            'is_active' => true,
        ]);

        CartItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $tenDayPackage->id,
            'quantity' => 1,
        ]);

        $items = CartItem::with(['product', 'package'])->where('user_id', $user->id)->get();
        $quote = app(VoucherService::class)->quoteCart($items, $user, $voucher->code);

        $this->assertSame(10000, $quote['discount_idr']);
        $this->assertSame(90000, $quote['final_idr']);
        $this->assertSame([$product->id], $quote['required_product_ids']);
    }

    public function test_bundle_calculates_each_quantity_from_its_own_unit_price(): void
    {
        [$user, $product, $firstPackage] = $this->catalogItem('Aurora', 100000, 5, 3);
        $secondPackage = Package::create([
            'product_id' => $product->id,
            'name' => '90 Days',
            'price' => 100000,
            'price_usdt' => 5,
        ]);
        $voucher = Voucher::create([
            'code' => 'NOSPLIT',
            'discount_percent' => 10,
            'max_discount' => 15000,
            'max_discount_usdt' => 0.5,
            'max_discount_usdc' => 0.5,
            'minimum_purchase' => 0,
            'usage_limit' => 10,
            'per_user_limit' => 0,
            'is_active' => true,
        ]);

        CartItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $firstPackage->id,
            'quantity' => 2,
        ]);
        CartItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $secondPackage->id,
            'quantity' => 1,
        ]);

        $items = CartItem::with(['product', 'package'])->where('user_id', $user->id)->get();
        $quote = app(VoucherService::class)->quoteCart($items, $user, $voucher->code);

        $this->assertSame(300000, $quote['base_idr']);
        $this->assertSame(30000, $quote['discount_idr']);
        $this->assertSame(270000, $quote['final_idr']);
        $this->assertSame(3, $quote['discount_units']);
        $this->assertSame(45000, $quote['max_discount_total']);
    }

    public function test_bundle_voucher_matches_per_license_example(): void
    {
        [$user, $aurora, $auroraPackage] = $this->catalogItem('Aurora', 20000, 1.25, 1);
        [, $drip, $dripPackage] = $this->catalogItem('Drip', 100000, 6, 1, $user);
        [, $fluoriteFf, $fluoriteFfPackage] = $this->catalogItem('Fluorite FF', 150000, 10, 1, $user);
        [, $fluoriteMl, $fluoriteMlPackage] = $this->catalogItem('Fluorite ML', 150000, 10, 4, $user);
        $voucher = Voucher::create([
            'code' => 'PERLICENSE',
            'discount_percent' => 10,
            'max_discount' => 10000,
            'max_discount_usdt' => 0.5,
            'max_discount_usdc' => 0.5,
            'minimum_purchase' => 0,
            'usage_limit' => 10,
            'per_user_limit' => 0,
            'is_active' => true,
        ]);

        foreach ([
            [$aurora, $auroraPackage, 1],
            [$drip, $dripPackage, 1],
            [$fluoriteFf, $fluoriteFfPackage, 1],
            [$fluoriteMl, $fluoriteMlPackage, 4],
        ] as [$product, $package, $quantity]) {
            CartItem::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'package_id' => $package->id,
                'quantity' => $quantity,
            ]);
        }

        $items = CartItem::with(['product', 'package'])->where('user_id', $user->id)->get();
        $quote = app(VoucherService::class)->quoteCart($items, $user, $voucher->code);

        $this->assertSame(870000, $quote['base_idr']);
        $this->assertSame(62000, $quote['discount_idr']);
        $this->assertSame(808000, $quote['final_idr']);
        $this->assertSame(7, $quote['discount_units']);
        $this->assertSame(70000, $quote['max_discount_total']);
    }

    public function test_cart_rejects_cross_account_changes_and_mismatched_packages(): void
    {
        [$owner, $firstProduct, $firstPackage] = $this->catalogItem('Aurora', 100000, 5, 1);
        [, $secondProduct, $secondPackage] = $this->catalogItem('Drip', 100000, 5, 1, $owner);
        $attacker = User::factory()->create();
        $item = CartItem::create([
            'user_id' => $owner->id,
            'product_id' => $firstProduct->id,
            'package_id' => $firstPackage->id,
            'quantity' => 1,
        ]);

        $this->actingAs($attacker)
            ->patchJson(route('cart.items.update', $item), ['quantity' => 2])
            ->assertNotFound();
        $this->actingAs($attacker)
            ->deleteJson(route('cart.items.destroy', $item))
            ->assertNotFound();
        $this->assertDatabaseHas('cart_items', [
            'id' => $item->id,
            'user_id' => $owner->id,
            'quantity' => 1,
        ]);

        $this->actingAs($owner)
            ->postJson(route('cart.items.store', $firstProduct), [
                'package_id' => $secondPackage->id,
                'quantity' => 1,
            ])
            ->assertNotFound();
        $this->assertDatabaseMissing('cart_items', [
            'user_id' => $owner->id,
            'product_id' => $secondProduct->id,
            'package_id' => $secondPackage->id,
        ]);
    }

    public function test_quantity_update_returns_authoritative_cart_totals_for_ajax(): void
    {
        [$user, $firstProduct, $firstPackage] = $this->catalogItem('Aurora', 20000, 1.25, 4);
        [, $secondProduct, $secondPackage] = $this->catalogItem('Drip', 100000, 6, 1, $user);
        $item = CartItem::create([
            'user_id' => $user->id,
            'product_id' => $firstProduct->id,
            'package_id' => $firstPackage->id,
            'quantity' => 1,
        ]);
        CartItem::create([
            'user_id' => $user->id,
            'product_id' => $secondProduct->id,
            'package_id' => $secondPackage->id,
            'quantity' => 1,
        ]);

        $this->actingAs($user)
            ->postJson(route('cart.items.update', $item), [
                '_method' => 'PATCH',
                'quantity' => 3,
                'subtotal_idr' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('item.quantity', 3)
            ->assertJsonPath('item.line_total_idr', 60000)
            ->assertJsonPath('item.line_total_usdt', 3.75)
            ->assertJsonPath('item.max_quantity', 4)
            ->assertJsonPath('cart.distinct_items', 2)
            ->assertJsonPath('cart.quantity', 4)
            ->assertJsonPath('cart.subtotal_idr', 160000)
            ->assertJsonPath('cart.subtotal_usdt', 9.75)
            ->assertJsonCount(2, 'cart.item_limits');
    }

    public function test_multi_item_reservation_rolls_back_when_one_package_is_short(): void
    {
        [$user, $firstProduct, $firstPackage] = $this->catalogItem('Aurora', 50000, 3, 1);
        [, $secondProduct, $secondPackage] = $this->catalogItem('Drip', 100000, 6, 0, $user);
        $order = Order::create([
            'order_id' => 'ORDER-ATOMIC-CART',
            'user_id' => $user->id,
            'product_id' => $firstProduct->id,
            'package_id' => $firstPackage->id,
            'quantity' => 2,
            'status' => 'pending',
            'payment_method' => 'gopay_qris',
            'price' => 150000,
            'expired_at' => now()->addMinutes(5),
        ]);
        $this->orderItem($order, $firstProduct, $firstPackage, 1);
        $this->orderItem($order, $secondProduct, $secondPackage, 1);

        try {
            app(StockReservationService::class)->reserve($order);
            $this->fail('The reservation should fail when one cart item has no stock.');
        } catch (\Exception $error) {
            $this->assertStringContainsString('every cart item', $error->getMessage());
        }

        $this->assertSame(0, LicenseStock::where('reserved_order_id', $order->id)->count());
    }

    public function test_binance_pay_and_direct_crypto_accept_the_whole_bundle_in_selected_usdc(): void
    {
        config([
            'services.binance.pay.enabled' => true,
            'services.binance.pay.pay_id' => '123456789',
            'services.binance.pay.qr_content' => 'generic-binance-qr',
            'services.binance.pay.api_key' => 'test-key',
            'services.binance.pay.api_secret' => 'test-secret',
            'services.crypto_direct.networks.usdcbsc.address' => '0x1111111111111111111111111111111111111111',
            'services.crypto_direct.networks.usdcbsc.contract' => '0x8ac76a51cc950d9822d68b83fe1ad97b32cd580d',
            'services.crypto_direct.networks.usdcbsc.rpc_url' => 'https://bsc-rpc.test',
        ]);

        [$user, $firstProduct, $firstPackage] = $this->catalogItem('Aurora', 50000, 3, 2);
        [, $secondProduct, $secondPackage] = $this->catalogItem('Drip', 100000, 6, 2, $user);
        foreach ([[$firstProduct, $firstPackage], [$secondProduct, $secondPackage]] as [$product, $package]) {
            CartItem::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'package_id' => $package->id,
                'quantity' => 1,
            ]);
        }

        $binanceResponse = $this->actingAs($user)
            ->postJson(route('cart.checkout'), [
                'payment_method' => 'binance_pay',
                'token' => 'usdc',
            ])
            ->assertOk()
            ->assertJsonPath('binance_pay_payment.token', 'USDC')
            ->assertJsonPath('binance_pay_payment.base_amount', '9.00000');
        $binanceOrder = Order::where('order_id', $binanceResponse->json('order_id'))->firstOrFail();
        $this->assertGreaterThan(9, (float) $binanceOrder->price);
        $binanceOrder->update(['status' => 'cancelled']);
        app(StockReservationService::class)->release($binanceOrder);

        foreach ([[$firstProduct, $firstPackage], [$secondProduct, $secondPackage]] as [$product, $package]) {
            CartItem::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'package_id' => $package->id,
                'quantity' => 1,
            ]);
        }

        $cryptoResponse = $this->actingAs($user)
            ->postJson(route('cart.checkout'), [
                'payment_method' => 'crypto',
                'coin' => 'usdcbsc',
            ])
            ->assertOk()
            ->assertJsonPath('crypto_payment.token', 'USDC')
            ->assertJsonPath('crypto_payment.base_amount', '9.00000');
        $cryptoOrder = Order::where('order_id', $cryptoResponse->json('order_id'))->firstOrFail();
        $this->assertSame(2, $cryptoOrder->items()->count());
        $this->assertSame(2, LicenseStock::where('reserved_order_id', $cryptoOrder->id)->count());
    }

    public function test_cancelling_bundle_releases_every_reserved_key(): void
    {
        [$user, $firstProduct, $firstPackage] = $this->catalogItem('Aurora', 50000, 3, 1);
        [, $secondProduct, $secondPackage] = $this->catalogItem('Drip', 100000, 6, 1, $user);
        $order = Order::create([
            'order_id' => 'ORDER-CANCEL-BUNDLE',
            'user_id' => $user->id,
            'product_id' => $firstProduct->id,
            'package_id' => $firstPackage->id,
            'quantity' => 2,
            'status' => 'pending',
            'payment_method' => 'crypto',
            'price' => 9,
            'expired_at' => now()->addMinutes(5),
        ]);
        $this->orderItem($order, $firstProduct, $firstPackage, 1);
        $this->orderItem($order, $secondProduct, $secondPackage, 1);
        app(StockReservationService::class)->reserve($order);
        $this->assertSame(2, LicenseStock::where('reserved_order_id', $order->id)->count());

        $this->actingAs($user)
            ->postJson("/cancel-order/{$order->id}")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertSame(0, LicenseStock::where('reserved_order_id', $order->id)->count());
    }

    public function test_expired_bundle_releases_every_reserved_key(): void
    {
        [$user, $firstProduct, $firstPackage] = $this->catalogItem('Aurora', 50000, 3, 1);
        [, $secondProduct, $secondPackage] = $this->catalogItem('Drip', 100000, 6, 1, $user);
        $order = Order::create([
            'order_id' => 'ORDER-EXPIRED-BUNDLE',
            'user_id' => $user->id,
            'product_id' => $firstProduct->id,
            'package_id' => $firstPackage->id,
            'quantity' => 2,
            'status' => 'pending',
            'payment_method' => 'crypto',
            'price' => 9,
            'expired_at' => now()->subMinutes(5),
        ]);
        $this->orderItem($order, $firstProduct, $firstPackage, 1);
        $this->orderItem($order, $secondProduct, $secondPackage, 1);
        app(StockReservationService::class)->reserve($order);

        app(PendingOrderExpirationService::class)->expire($user->id);

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(0, LicenseStock::where('reserved_order_id', $order->id)->count());
    }

    public function test_pay_again_recreates_the_original_bundle_not_the_current_cart(): void
    {
        config([
            'services.crypto_direct.networks.usdtbsc.address' => '0x1111111111111111111111111111111111111111',
            'services.crypto_direct.networks.usdtbsc.contract' => '0x55d398326f99059fF775485246999027B3197955',
            'services.crypto_direct.networks.usdtbsc.rpc_url' => 'https://bsc-rpc.test',
        ]);

        [$user, $firstProduct, $firstPackage] = $this->catalogItem('Aurora', 50000, 3, 2);
        [, $secondProduct, $secondPackage] = $this->catalogItem('Drip', 100000, 6, 1, $user);
        $oldOrder = Order::create([
            'order_id' => 'ORDER-OLD-BUNDLE',
            'user_id' => $user->id,
            'product_id' => $firstProduct->id,
            'package_id' => $firstPackage->id,
            'quantity' => 3,
            'status' => 'cancelled',
            'payment_method' => 'crypto',
            'price' => 12,
            'payment_payload' => [
                'type' => 'direct_crypto',
                'network' => 'usdtbsc',
                'token' => 'USDT',
            ],
            'expired_at' => now()->subMinute(),
        ]);
        $this->orderItem($oldOrder, $firstProduct, $firstPackage, 2);
        $this->orderItem($oldOrder, $secondProduct, $secondPackage, 1);
        CartItem::create([
            'user_id' => $user->id,
            'product_id' => $firstProduct->id,
            'package_id' => $firstPackage->id,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/pay-again/{$oldOrder->id}")
            ->assertOk();

        $replacement = Order::where('order_id', $response->json('order_id'))->firstOrFail();
        $expectedSignature = collect([
            $firstPackage->id.':2',
            $secondPackage->id.':1',
        ])->sort()->implode('|');
        $this->assertSame($expectedSignature, $replacement->items()->orderBy('package_id')->get()
            ->map(fn ($item) => $item->package_id.':'.$item->quantity)
            ->sort()
            ->implode('|'));
        $this->assertSame(1, CartItem::where('user_id', $user->id)->sum('quantity'));
        $this->assertSame($replacement->id, $oldOrder->fresh()->replaced_by);
    }

    private function catalogItem(
        string $name,
        int $price,
        float $priceUsdt,
        int $stock,
        ?User $user = null
    ): array {
        $user ??= User::factory()->create();
        $category = Category::firstOrCreate(['slug' => 'cart-test'], ['name' => 'Cart Test']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => strtolower($name).'-'.uniqid(),
            'status' => Product::STATUS_READY,
            'description' => 'Cart checkout test product.',
        ]);
        $package = Package::create([
            'product_id' => $product->id,
            'name' => '30 Days',
            'price' => $price,
            'price_usdt' => $priceUsdt,
        ]);

        for ($index = 1; $index <= $stock; $index++) {
            LicenseStock::create([
                'product_id' => $product->id,
                'package_id' => $package->id,
                'license_key' => strtoupper($name).'-KEY-'.$index.'-'.uniqid(),
                'is_sold' => false,
            ]);
        }

        return [$user, $product, $package];
    }

    private function enableGopayQris(): void
    {
        config([
            'services.gopay_qris.enabled' => true,
            'services.gopay_qris.static_payload' => self::STATIC_QRIS,
            'services.gopay_qris.merchant_name' => 'Aksa Xiterz',
            'services.gopay_qris.merchant_reference' => 'ID102432979310',
            'services.gopay_qris.expires_minutes' => 10,
            'services.gopay_qris.recovery_hours' => 72,
            'services.gopay_qris.unique_max' => 999,
            'services.gopay_qris.webhook_token' => 'cart-checkout-token',
            'services.gopay_qris.webhook_secret' => 'cart-checkout-secret',
            'services.gopay_qris.allowed_devices' => ['aksa-gopay-primary'],
        ]);
    }

    private function orderItem(Order $order, Product $product, Package $package, int $quantity): OrderItem
    {
        return OrderItem::create([
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
    }
}
