<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Category;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PDO;
use Tests\TestCase;

class DedicatedCheckoutFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for dedicated checkout tests.');
        }

        parent::setUp();
    }

    public function test_guest_must_login_before_opening_product_or_cart_checkout(): void
    {
        [, $product, $package] = $this->catalogItem('Guest Checkout', 20_000, 1.25, 1);

        $this->get(route('checkout.cart'))
            ->assertRedirect(route('login'));

        $this->get(route('checkout.product', [
            'product' => $product,
            'package' => $package->id,
            'quantity' => 1,
        ]))
            ->assertRedirect(route('login'));
    }

    public function test_buy_now_renders_only_selected_package_without_mutating_cart(): void
    {
        [$user, $product, $package] = $this->catalogItem('Buy Now Target', 20_000, 1.25, 3);
        [, $cartProduct, $cartPackage] = $this->catalogItem('Existing Cart Product', 90_000, 5.5, 1, $user);
        CartItem::create([
            'user_id' => $user->id,
            'product_id' => $cartProduct->id,
            'package_id' => $cartPackage->id,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($user)
            ->get(route('checkout.product', [
                'product' => $product,
                'package' => $package->id,
                'quantity' => 2,
                'price' => 1,
            ]))
            ->assertOk()
            ->assertSee('Buy Now Target')
            ->assertSee($package->name)
            ->assertSee('2 licenses')
            ->assertSee('Rp 40.000')
            ->assertSee('data-price-usd="2.5"', false)
            ->assertSee('platform fee + unique amount')
            ->assertSee('action="'.route('checkout.product.process', $product).'"', false)
            ->assertSee('name="package_id" value="'.$package->id.'"', false)
            ->assertSee('name="quantity" value="2"', false)
            ->assertDontSee('Existing Cart Product');

        $this->assertDatabaseHas('cart_items', [
            'user_id' => $user->id,
            'product_id' => $cartProduct->id,
            'package_id' => $cartPackage->id,
            'quantity' => 1,
        ]);
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseCount('orders', 0);
        $this->assertStringNotContainsString('name="price"', $response->getContent());
        $this->assertSame(2, substr_count($response->getContent(), 'data-currency-switcher'));
    }

    public function test_direct_checkout_rejects_a_package_owned_by_another_product(): void
    {
        [$user, $product] = $this->catalogItem('Package Owner', 20_000, 1.25, 1);
        [, , $otherPackage] = $this->catalogItem('Other Package Owner', 40_000, 2.5, 1, $user);

        $this->actingAs($user)
            ->get(route('checkout.product', [
                'product' => $product,
                'package' => $otherPackage->id,
            ]))
            ->assertNotFound();

        $this->enableGopayQris();

        $this->actingAs($user)
            ->postJson(route('checkout.product.process', $product), [
                'payment_method' => 'gopay_qris',
                'package_id' => $otherPackage->id,
                'quantity' => 1,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, LicenseStock::whereNotNull('reserved_order_id')->count());
    }

    public function test_direct_checkout_validates_quantity_and_fails_closed_when_stock_is_short(): void
    {
        [$user, $product, $package] = $this->catalogItem('Quantity Guard', 30_000, 2, 1);
        $productUrl = route('products.show', $product);

        $this->actingAs($user)
            ->from($productUrl)
            ->get(route('checkout.product', [
                'product' => $product,
                'package' => $package->id,
                'quantity' => 0,
            ]))
            ->assertRedirect($productUrl)
            ->assertSessionHasErrors('quantity');

        $this->actingAs($user)
            ->from($productUrl)
            ->get(route('checkout.product', [
                'product' => $product,
                'package' => $package->id,
                'quantity' => CartService::MAX_TOTAL_QUANTITY + 1,
            ]))
            ->assertRedirect($productUrl)
            ->assertSessionHasErrors('quantity');

        $response = $this->actingAs($user)
            ->get(route('checkout.product', [
                'product' => $product,
                'package' => $package->id,
                'quantity' => 2,
            ]))
            ->assertOk()
            ->assertSee('Stock or product availability changed before payment.')
            ->assertSee('Checkout Paused');

        $this->assertMatchesRegularExpression(
            '/name="payment_method"\s+value="crypto"[^>]*disabled/s',
            $response->getContent()
        );
        $this->assertMatchesRegularExpression(
            '/id="checkoutSubmitButton"[^>]*disabled/s',
            $response->getContent()
        );

        $this->enableGopayQris();

        $this->actingAs($user)
            ->postJson(route('checkout.product.process', $product), [
                'payment_method' => 'gopay_qris',
                'package_id' => $package->id,
                'quantity' => CartService::MAX_TOTAL_QUANTITY + 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $this->actingAs($user)
            ->postJson(route('checkout.product.process', $product), [
                'payment_method' => 'gopay_qris',
                'package_id' => $package->id,
                'quantity' => 2,
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Automatic delivery does not have enough license stock for this quantity.'
            );

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_successful_buy_now_returns_an_owner_scoped_instruction_page_and_keeps_the_cart(): void
    {
        [$user, $product, $package] = $this->catalogItem('Instruction Target', 42_000, 2.75, 2);
        [, $cartProduct, $cartPackage] = $this->catalogItem('Preserved Cart Item', 18_000, 1.1, 1, $user);
        $otherUser = User::factory()->create();
        CartItem::create([
            'user_id' => $user->id,
            'product_id' => $cartProduct->id,
            'package_id' => $cartPackage->id,
            'quantity' => 1,
        ]);
        $this->enableGopayQris();

        $response = $this->actingAs($user)
            ->postJson(route('checkout.product.process', $product), [
                'payment_method' => 'gopay_qris',
                'package_id' => $package->id,
                'quantity' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('method', 'gopay_qris')
            ->assertJsonStructure(['order_id', 'instruction_url']);

        $order = Order::where('order_id', $response->json('order_id'))->firstOrFail();
        $instructionUrl = route('orders.payment', ['orderId' => $order->order_id]);

        $response->assertJsonPath('instruction_url', $instructionUrl);
        $this->assertSame(2, $order->items()->sum('quantity'));
        $this->assertDatabaseHas('cart_items', [
            'user_id' => $user->id,
            'product_id' => $cartProduct->id,
            'package_id' => $cartPackage->id,
            'quantity' => 1,
        ]);

        $payload = $order->payment_payload;
        $payload['provider_secret_for_test'] = 'NEVER-EXPOSE-THIS';
        $order->update(['payment_payload' => $payload]);

        $this->actingAs($user)
            ->get($instructionUrl)
            ->assertOk()
            ->assertSee($order->order_id)
            ->assertSee('Payment Instruction')
            ->assertSee('id="paymentInstructionQris"', false)
            ->assertSee('data-cancel-payment-form', false)
            ->assertSee('data-cancel-payment', false)
            ->assertSee('Waiting for Payment...', false)
            ->assertDontSee('NEVER-EXPOSE-THIS')
            ->assertDontSee('id="aksaCryptoModal"', false)
            ->assertDontSee('id="aksaBinancePayModal"', false);

        $this->actingAs($otherUser)
            ->get($instructionUrl)
            ->assertNotFound();

        auth()->logout();

        $this->get($instructionUrl)
            ->assertRedirect(route('login'));
    }

    public function test_cart_checkout_rejects_a_stale_cart_signature_before_creating_an_invoice(): void
    {
        [$user, $product, $package] = $this->catalogItem('Signature Guard', 25_000, 1.5, 3);
        $item = CartItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]);
        $cartService = app(CartService::class);
        $staleSignature = $cartService->signature($cartService->items($user));
        $item->update(['quantity' => 2]);
        $this->enableGopayQris();

        $this->actingAs($user)
            ->postJson(route('cart.checkout'), [
                'payment_method' => 'gopay_qris',
                'cart_signature' => $staleSignature,
            ])
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'Your cart changed in another tab. Review it again before paying.'
            );

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('cart_items', [
            'id' => $item->id,
            'quantity' => 2,
        ]);
    }

    public function test_cart_mutations_share_the_checkout_lock(): void
    {
        [$user, $product, $package] = $this->catalogItem('Locked Cart', 22_000, 1.35, 1);
        config(['services.payments.checkout_lock_wait_seconds' => 0]);
        $heldLock = Cache::lock("payment-checkout:user:{$user->id}", 120);
        $this->assertTrue($heldLock->get());

        try {
            $this->actingAs($user)
                ->postJson(route('cart.items.store', $product), [
                    'package_id' => $package->id,
                    'quantity' => 1,
                ])
                ->assertStatus(409)
                ->assertJsonPath(
                    'message',
                    'Checkout is updating your cart. Wait a moment and try again.'
                );
        } finally {
            $heldLock->release();
        }

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_expired_crypto_instruction_hides_old_credentials_but_keeps_recovery_verification(): void
    {
        [$user, $product, $package] = $this->catalogItem('Expired Instruction', 31_000, 2.125, 1);
        $order = Order::create([
            'order_id' => 'ORDER-EXPIRED-INSTRUCTION',
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
            'status' => 'cancelled',
            'payment_method' => 'crypto',
            'price' => '2.125321',
            'expired_at' => now()->subMinutes(5),
            'payment_payload' => [
                'type' => 'direct_crypto',
                'token' => 'USDT',
                'network' => 'usdttrc20',
                'network_label' => 'TRON (TRC20)',
                'address' => 'T-OLD-ADDRESS-MUST-BE-HIDDEN',
                'contract' => 'OLD-CONTRACT-MUST-BE-HIDDEN',
                'amount' => '2.125321',
                'provider_diagnostic' => 'INTERNAL-DIAGNOSTIC-MUST-BE-HIDDEN',
            ],
        ]);

        $this->actingAs($user)
            ->get(route('orders.payment', ['orderId' => $order->order_id]))
            ->assertOk()
            ->assertSee('Payment window expired')
            ->assertSee('Verify Sent Payment')
            ->assertDontSee('T-OLD-ADDRESS-MUST-BE-HIDDEN')
            ->assertDontSee('OLD-CONTRACT-MUST-BE-HIDDEN')
            ->assertDontSee('INTERNAL-DIAGNOSTIC-MUST-BE-HIDDEN');
    }

    public function test_paid_instruction_renders_the_result_without_reusing_payment_credentials(): void
    {
        [$user, $product, $package] = $this->catalogItem('Paid Instruction', 51_000, 3.25, 1);
        $order = Order::create([
            'order_id' => 'ORDER-PAID-INSTRUCTION',
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
            'status' => 'paid',
            'paid_at' => now(),
            'payment_method' => 'gopay_qris',
            'price' => 51_321,
            'expired_at' => now()->addMinutes(5),
            'payment_payload' => [
                'type' => 'gopay_qris_notification',
                'qr_payload' => 'OLD-PAID-QR-MUST-BE-HIDDEN',
                'base_amount' => 51_000,
                'unique_amount' => 321,
                'total_payment' => 51_321,
                'provider_secret' => 'PAID-SECRET-MUST-BE-HIDDEN',
            ],
        ]);

        $this->actingAs($user)
            ->get(route('orders.payment', ['orderId' => $order->order_id]))
            ->assertOk()
            ->assertSee('Payment confirmed')
            ->assertSee('payment-confirmed-mark', false)
            ->assertSee('payment-confirmed-check', false)
            ->assertSee('data-invoice-total', false)
            ->assertSee('order-id-copy', false)
            ->assertSee('Payment Verified', false)
            ->assertSee('Open Orders')
            ->assertDontSee('OLD-PAID-QR-MUST-BE-HIDDEN')
            ->assertDontSee('PAID-SECRET-MUST-BE-HIDDEN')
            ->assertDontSee('id="paymentInstructionQris"', false);
    }

    public function test_empty_cart_checkout_redirects_to_cart_with_an_error(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('checkout.cart'))
            ->assertRedirect(route('cart.index'))
            ->assertSessionHasErrors([
                'cart' => 'Your cart is empty.',
            ]);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_cart_checkout_review_is_user_scoped_and_uses_server_prices(): void
    {
        [$user, $product, $package] = $this->catalogItem('Owned Checkout Item', 20_000, 1.25, 2);
        [$otherUser, $otherProduct, $otherPackage] = $this->catalogItem(
            'Another Account Item',
            500_000,
            30,
            1
        );
        CartItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 2,
        ]);
        CartItem::create([
            'user_id' => $otherUser->id,
            'product_id' => $otherProduct->id,
            'package_id' => $otherPackage->id,
            'quantity' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('checkout.cart', [
                'price' => 1,
                'subtotal_idr' => 1,
                'product_id' => $otherProduct->id,
            ]))
            ->assertOk()
            ->assertSee('Owned Checkout Item')
            ->assertSee('Rp 40.000')
            ->assertSee('data-price-usd="2.5"', false)
            ->assertSee('action="'.route('cart.checkout').'"', false)
            ->assertDontSee('Another Account Item');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 2);
    }

    public function test_cart_and_checkout_disable_when_stock_falls_below_quantity(): void
    {
        [$user, $product, $package] = $this->catalogItem('Low Stock Cart', 35_000, 2.5, 2);
        CartItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 2,
        ]);
        LicenseStock::where('package_id', $package->id)
            ->oldest('id')
            ->firstOrFail()
            ->update(['is_sold' => true]);

        $cart = $this->actingAs($user)
            ->get(route('cart.index'))
            ->assertOk()
            ->assertSee('data-cart-checkout-paused', false)
            ->assertSee('This selection needs to be reviewed')
            ->assertSee('Review Unavailable Items')
            ->assertDontSee('Continue to Checkout')
            ->assertDontSee('href="'.route('checkout.cart').'"', false);

        $this->assertMatchesRegularExpression(
            '/name="quantity"\s+value="1"[^>]*aria-label="Decrease Low Stock Cart 30 Days quantity"(?![^>]*disabled)/s',
            $cart->getContent()
        );

        $checkout = $this->actingAs($user)
            ->get(route('checkout.cart'))
            ->assertOk()
            ->assertSee('Stock or product availability changed before payment.')
            ->assertSee('Checkout Paused');

        $this->assertMatchesRegularExpression(
            '/name="payment_method"\s+value="crypto"[^>]*disabled/s',
            $checkout->getContent()
        );
        $this->assertMatchesRegularExpression(
            '/id="checkoutSubmitButton"[^>]*disabled/s',
            $checkout->getContent()
        );
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_product_cart_and_checkout_pages_do_not_embed_payment_modals(): void
    {
        [$user, $product, $package] = $this->catalogItem('Lean Checkout Page', 25_000, 1.5, 1);
        CartItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]);

        $productResponse = $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('id="buyNowBtn"', false)
            ->assertSee('id="addToCartBtn"', false)
            ->assertDontSee('name="payment_method"', false);

        $cartResponse = $this->actingAs($user)
            ->get(route('cart.index'))
            ->assertOk()
            ->assertSee('Continue to Checkout')
            ->assertDontSee('name="payment_method"', false);

        $checkoutResponse = $this->actingAs($user)
            ->get(route('checkout.product', [
                'product' => $product,
                'package' => $package->id,
            ]))
            ->assertOk()
            ->assertSee('name="payment_method"', false)
            ->assertSee('id="checkoutVoucherCode"', false);

        foreach ([$productResponse, $cartResponse, $checkoutResponse] as $response) {
            $response
                ->assertDontSee('id="aksaQrisModal"', false)
                ->assertDontSee('id="aksaBinancePayModal"', false)
                ->assertDontSee('id="aksaCryptoModal"', false)
                ->assertDontSee('id="aksaPaymentSuccessModal"', false);
        }

        $this->assertDatabaseCount('orders', 0);
    }

    private function catalogItem(
        string $name,
        int $price,
        float $priceUsdt,
        int $stock,
        ?User $user = null
    ): array {
        $user ??= User::factory()->create();
        $suffix = strtolower(str_replace(' ', '-', $name)).'-'.uniqid();
        $category = Category::create([
            'name' => $name.' Category',
            'slug' => 'checkout-category-'.$suffix,
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => 'checkout-product-'.$suffix,
            'status' => Product::STATUS_READY,
            'is_visible' => true,
            'description' => 'Dedicated checkout feature test product.',
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
                'license_key' => strtoupper(str_replace(' ', '-', $name)).'-'.$index.'-'.uniqid(),
                'is_sold' => false,
            ]);
        }

        return [$user, $product, $package];
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
            'services.gopay_qris.webhook_token' => 'dedicated-checkout-token',
            'services.gopay_qris.webhook_secret' => 'dedicated-checkout-secret',
            'services.gopay_qris.allowed_devices' => ['aksa-gopay-primary'],
        ]);
    }
}
