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
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

class LegacyQrisCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private const STATIC_QRIS = '00020101021126610014COM.GO-JEK.WWW01189360091438659284520210G8659284520303UMI51440014ID.CO.QRIS.WWW0215ID10243297931020303UMI5204729953033605802ID5911Aksa Xiterz6006MALANG61056515362070703A0163045DEF';

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for legacy QRIS compatibility tests.');
        }

        parent::setUp();

        Http::fake();
    }

    public function test_pay_again_remaps_legacy_pakasir_order_to_enabled_gopay_qris(): void
    {
        $this->enableGopayQris();
        [$user, $product, $package] = $this->catalogWithStock();
        $oldOrder = $this->legacyPakasirOrder($user, $product, $package);

        $response = $this->actingAs($user)
            ->postJson("/pay-again/{$oldOrder->id}")
            ->assertOk()
            ->assertJsonPath('method', 'gopay_qris');

        $replacement = Order::where('order_id', $response->json('order_id'))->firstOrFail();

        $this->assertSame('gopay_qris', $replacement->payment_method);
        $this->assertSame('pending', $replacement->status);
        $this->assertSame($replacement->id, $oldOrder->fresh()->replaced_by);
        $this->assertSame('cancelled', $oldOrder->fresh()->status);
        $this->assertDatabaseCount('orders', 2);
        Http::assertNothingSent();
    }

    public function test_pay_again_keeps_legacy_pakasir_order_untouched_when_no_qris_checkout_is_enabled(): void
    {
        config(['services.gopay_qris.enabled' => false]);
        [$user, $product, $package] = $this->catalogWithStock();
        $oldOrder = $this->legacyPakasirOrder($user, $product, $package);

        $this->actingAs($user)
            ->postJson("/pay-again/{$oldOrder->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'QRIS checkout is currently unavailable.');

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('cancelled', $oldOrder->fresh()->status);
        $this->assertNull($oldOrder->fresh()->replaced_by);
        $this->assertDatabaseHas('license_stocks', [
            'package_id' => $package->id,
            'is_sold' => false,
            'reserved_order_id' => null,
        ]);
        Http::assertNothingSent();
    }

    public function test_qris_option_is_visibly_disabled_when_both_checkout_providers_are_off(): void
    {
        config(['services.gopay_qris.enabled' => false]);
        [$user, $product, $package] = $this->catalogWithStock();

        $this->get("/product/{$product->slug}")
            ->assertOk()
            ->assertSee('data-payment-method=""', false)
            ->assertSee('QRIS checkout is temporarily unavailable');

        CartItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('cart.index'))
            ->assertOk()
            ->assertSee('data-cart-payment="" disabled', false)
            ->assertSee('QRIS checkout is temporarily unavailable');
    }

    private function enableGopayQris(): void
    {
        config([
            'services.gopay_qris.enabled' => true,
            'services.gopay_qris.static_payload' => self::STATIC_QRIS,
            'services.gopay_qris.merchant_name' => 'Aksa Xiterz',
            'services.gopay_qris.merchant_reference' => 'ID102432979310',
            'services.gopay_qris.expires_minutes' => 10,
            'services.gopay_qris.unique_max' => 999,
            'services.gopay_qris.webhook_token' => 'checkout-test-token',
            'services.gopay_qris.webhook_secret' => 'checkout-test-secret',
            'services.gopay_qris.allowed_devices' => ['aksa-gopay-primary'],
        ]);
    }

    private function catalogWithStock(): array
    {
        $user = User::factory()->create();
        $category = Category::create([
            'name' => 'Pakasir Toggle',
            'slug' => 'pakasir-toggle-'.uniqid(),
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Pakasir Toggle Product '.uniqid(),
            'slug' => 'pakasir-toggle-product-'.uniqid(),
            'status' => Product::STATUS_READY,
            'is_visible' => true,
            'description' => 'Pakasir checkout toggle test product.',
        ]);
        $package = Package::create([
            'product_id' => $product->id,
            'name' => '1 Day',
            'price' => 50_000,
            'price_usdt' => 3,
        ]);

        LicenseStock::create([
            'product_id' => $product->id,
            'package_id' => $package->id,
            'license_key' => 'PAKASIR-TOGGLE-'.uniqid(),
            'is_sold' => false,
        ]);

        return [$user, $product, $package];
    }

    private function legacyPakasirOrder(User $user, Product $product, Package $package): Order
    {
        return Order::create([
            'order_id' => 'ORDER-LEGACY-PAKASIR-'.strtoupper(uniqid()),
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
            'status' => 'cancelled',
            'payment_method' => 'pakasir',
            'price' => $package->price,
            'expired_at' => now()->subMinute(),
        ]);
    }
}
