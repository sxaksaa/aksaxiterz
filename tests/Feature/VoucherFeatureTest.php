<?php

namespace Tests\Feature;

use App\Exceptions\VoucherException;
use App\Models\Category;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\Voucher;
use App\Services\PaymentService;
use App\Services\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

class VoucherFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for voucher feature tests.');
        }

        parent::setUp();
    }

    public function test_percentage_voucher_uses_separate_qris_and_usdt_caps(): void
    {
        [$user, , $package] = $this->makeCatalog(250000, 15);
        $voucher = $this->makeVoucher();

        $quote = app(VoucherService::class)->quote($package, $user, $voucher->code);
        $cryptoQuote = app(VoucherService::class)->quote(
            $package,
            $user,
            $voucher->code,
            null,
            null,
            false,
            'crypto',
            'usdtbsc'
        );

        $this->assertSame(15000, $quote['discount_idr']);
        $this->assertSame(235000, $quote['final_idr']);
        $this->assertSame(0.25, $cryptoQuote['discount_usdt']);
        $this->assertSame(14.75, $cryptoQuote['final_usdt']);
        $this->assertSame('USDT', $cryptoQuote['token']);
    }

    public function test_quantity_uses_combined_subtotal_but_keeps_one_voucher_cap(): void
    {
        [$user, , $package] = $this->makeCatalog(100000, 6);
        $voucher = $this->makeVoucher();

        $quote = app(VoucherService::class)->quote(
            $package,
            $user,
            $voucher->code,
            null,
            null,
            false,
            'pakasir',
            null,
            3
        );
        $cryptoQuote = app(VoucherService::class)->quote(
            $package,
            $user,
            $voucher->code,
            null,
            null,
            false,
            'crypto',
            'usdtbsc',
            3
        );

        $this->assertSame(3, $quote['quantity']);
        $this->assertSame(300000, $quote['base_idr']);
        $this->assertSame(15000, $quote['discount_idr']);
        $this->assertSame(285000, $quote['final_idr']);
        $this->assertSame(18.0, $cryptoQuote['base_usdt']);
        $this->assertSame(0.25, $cryptoQuote['discount_usdt']);
        $this->assertSame(17.75, $cryptoQuote['final_usdt']);
    }

    public function test_usdc_uses_its_own_discount_cap_and_expiry_is_returned(): void
    {
        [$user, , $package] = $this->makeCatalog(250000, 15);
        $expiresAt = now()->addDay()->startOfMinute();
        $voucher = $this->makeVoucher([
            'max_discount_usdt' => 0.25,
            'max_discount_usdc' => 0.4,
            'expires_at' => $expiresAt,
        ]);

        $quote = app(VoucherService::class)->quote(
            $package,
            $user,
            $voucher->code,
            null,
            null,
            false,
            'crypto',
            'usdcbsc'
        );

        $this->assertSame(0.4, $quote['discount_usdt']);
        $this->assertSame(14.6, $quote['final_usdt']);
        $this->assertSame('USDC', $quote['token']);
        $this->assertSame($expiresAt->toIso8601String(), $quote['expires_at']);
    }

    public function test_voucher_enforces_minimum_purchase(): void
    {
        [$user, , $package] = $this->makeCatalog(45000, 3);
        $voucher = $this->makeVoucher(['minimum_purchase' => 50000]);

        $this->expectException(VoucherException::class);
        $this->expectExceptionMessage('Minimum purchase');

        app(VoucherService::class)->quote($package, $user, $voucher->code);
    }

    public function test_expired_voucher_is_rejected_server_side(): void
    {
        [$user, , $package] = $this->makeCatalog(100000, 6);
        $voucher = $this->makeVoucher(['expires_at' => now()->subMinute()]);

        $this->expectException(VoucherException::class);
        $this->expectExceptionMessage('expired');

        app(VoucherService::class)->quote($package, $user, $voucher->code);
    }

    public function test_preview_uses_selected_coin_cap_and_does_not_expose_voucher_id(): void
    {
        [$user, , $package] = $this->makeCatalog(250000, 15);
        $this->makeVoucher([
            'max_discount_usdt' => 0.25,
            'max_discount_usdc' => 0.4,
        ]);

        $response = $this->actingAs($user)->postJson(route('vouchers.preview'), [
            'code' => 'AKSA10',
            'package_id' => $package->id,
            'payment_method' => 'crypto',
            'coin' => 'usdcbsc',
        ]);

        $response->assertOk()
            ->assertJsonPath('token', 'USDC')
            ->assertJsonPath('discount_usdt', 0.4)
            ->assertJsonMissingPath('voucher_id');
    }

    public function test_preview_rejects_quantity_above_available_stock(): void
    {
        [$user, , $package] = $this->makeCatalog(100000, 6, true);
        $this->makeVoucher();

        $this->actingAs($user)->postJson(route('vouchers.preview'), [
            'code' => 'AKSA10',
            'package_id' => $package->id,
            'payment_method' => 'pakasir',
            'quantity' => 2,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The selected quantity is no longer available.');
    }

    public function test_cancelled_order_releases_voucher_for_the_same_account(): void
    {
        [$user, $product, $package] = $this->makeCatalog(100000, 6);
        $voucher = $this->makeVoucher();
        $order = Order::create([
            'order_id' => 'ORDER-VOUCHER-RELEASE',
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'voucher_id' => $voucher->id,
            'status' => 'pending',
            'payment_method' => 'pakasir',
            'price' => 90000,
        ]);

        try {
            app(VoucherService::class)->quote($package, $user, $voucher->code);
            $this->fail('Pending voucher order should use the per-account limit.');
        } catch (VoucherException $error) {
            $this->assertStringContainsString('already used', $error->getMessage());
        }

        $order->update(['status' => 'cancelled']);
        $quote = app(VoucherService::class)->quote($package, $user, $voucher->code);

        $this->assertSame(10000, $quote['discount_idr']);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_pakasir_invoice_uses_discounted_price_and_reserves_voucher(): void
    {
        config([
            'services.pakasir.slug' => 'aksaxiterz',
            'services.pakasir.api_key' => 'test-key',
            'services.pakasir.url' => 'https://app.pakasir.test',
            'services.pakasir.return_url' => 'https://aksaxiterz.test/orders',
        ]);

        [$user, $product, $package] = $this->makeCatalog(100000, 6, true);
        $voucher = $this->makeVoucher();

        Http::fake(function ($request) {
            if ($request->url() === 'https://app.pakasir.test/api/transactioncreate/qris') {
                return Http::response([
                    'payment' => [
                        'project' => 'aksaxiterz',
                        'order_id' => $request['order_id'],
                        'amount' => 90000,
                        'fee' => 0,
                        'total_payment' => 90000,
                        'payment_method' => 'qris',
                        'payment_number' => '000201010212',
                        'expired_at' => now()->addHour()->toIso8601String(),
                    ],
                ]);
            }

            return Http::response([], 404);
        });

        $result = app(PaymentService::class)->createPakasirPayment(
            $user,
            $product->id,
            $package->id,
            null,
            $voucher->code
        );

        $this->assertSame('90000.000000', $result['order']->price);
        $this->assertSame($voucher->id, $result['order']->voucher_id);
        Http::assertSent(fn ($request): bool => $request['amount'] === 90000);
    }

    public function test_crypto_invoice_uses_same_effective_discount(): void
    {
        config([
            'services.crypto_direct.networks.usdtbsc.address' => '0x1111111111111111111111111111111111111111',
            'services.crypto_direct.networks.usdtbsc.contract' => '0x55d398326f99059fF775485246999027B3197955',
            'services.crypto_direct.networks.usdtbsc.rpc_url' => 'https://bsc-rpc.test',
        ]);

        [$user, $product, $package] = $this->makeCatalog(250000, 15, true);
        $voucher = $this->makeVoucher();

        $result = app(PaymentService::class)->createCryptoPayment(
            $user,
            $product->id,
            $package->id,
            'usdtbsc',
            null,
            $voucher->code
        );

        $this->assertSame($voucher->id, $result['order']->voucher_id);
        $this->assertSame('14.750000', $result['crypto_payment']['base_amount']);
        $this->assertGreaterThan(14.75, (float) $result['order']->price);
    }

    public function test_admin_can_create_voucher_and_storefront_shows_package_savings(): void
    {
        config(['admin.emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        [, $product] = $this->makeCatalog(20000, 1.25);
        Package::create([
            'product_id' => $product->id,
            'name' => '30 Days',
            'price' => 250000,
            'price_usdt' => 15,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.vouchers.store'), [
                'code' => 'aksa10',
                'discount_percent' => 10,
                'max_discount' => 15000,
                'max_discount_usdt' => 0.25,
                'max_discount_usdc' => 0.25,
                'minimum_purchase' => 45000,
                'usage_limit' => 20,
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.vouchers.index'));

        $this->assertDatabaseHas('vouchers', [
            'code' => 'AKSA10',
            'discount_percent' => 10,
            'max_discount' => 15000,
            'minimum_purchase' => 45000,
            'per_user_limit' => 0,
        ]);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('Best Value')
            ->assertSee('Save Rp 350,000')
            ->assertSee('data-usd="Save $22.50"', false)
            ->assertSee('58% vs daily')
            ->assertSee('data-usd="60% vs daily"', false)
            ->assertSee('data-usd="$0.50 per day"', false)
            ->assertSee('Enter voucher code')
            ->assertSee('Join our Discord server to get promo codes.');
    }

    private function makeVoucher(array $overrides = []): Voucher
    {
        return Voucher::create(array_merge([
            'code' => 'AKSA10',
            'discount_percent' => 10,
            'max_discount' => 15000,
            'max_discount_usdt' => 0.25,
            'max_discount_usdc' => 0.25,
            'minimum_purchase' => 45000,
            'usage_limit' => 20,
            'per_user_limit' => 1,
            'is_active' => true,
        ], $overrides));
    }

    private function makeCatalog(int $price, float $priceUsdt, bool $withStock = false): array
    {
        $user = User::factory()->create();
        $category = Category::firstOrCreate(['slug' => 'voucher-test'], ['name' => 'Voucher Test']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Voucher Product '.uniqid(),
            'slug' => 'voucher-product-'.uniqid(),
            'description' => 'Voucher test product.',
        ]);
        $package = Package::create([
            'product_id' => $product->id,
            'name' => '1 Day',
            'price' => $price,
            'price_usdt' => $priceUsdt,
        ]);

        if ($withStock) {
            LicenseStock::create([
                'product_id' => $product->id,
                'package_id' => $package->id,
                'license_key' => 'VOUCHER-STOCK-'.uniqid(),
                'is_sold' => false,
            ]);
        }

        return [$user, $product, $package];
    }
}
