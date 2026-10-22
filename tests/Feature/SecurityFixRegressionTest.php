<?php

namespace Tests\Feature;

use App\Exceptions\VoucherException;
use App\Models\Category;
use App\Models\License;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\Voucher;
use App\Services\DirectCryptoOrderVerifier;
use App\Services\OrderFulfillmentService;
use App\Services\PaymentService;
use App\Services\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

class SecurityFixRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelled_matchable_invoices_keep_global_and_user_voucher_quota(): void
    {
        [$user, $product, $package, $voucher] = $this->catalog();
        foreach (['crypto', 'binance_pay', 'gopay_qris'] as $method) {
            $order = Order::create([
                'order_id' => 'ORDER-QUOTA-'.$method, 'user_id' => $user->id,
                'product_id' => $product->id, 'package_id' => $package->id,
                'voucher_id' => $voucher->id, 'status' => 'cancelled',
                'payment_method' => $method, 'price' => 90000,
                'payment_match_key' => 'quota-'.$method, 'expired_at' => now()->addMinutes(10),
            ]);
            $this->assertQuoteRejected($package, $user, $voucher, 'already used');
            $voucher->update(['usage_limit' => 1]);
            $this->assertQuoteRejected($package, User::factory()->create(), $voucher, 'usage limit');
            $voucher->update(['usage_limit' => 20]);
            $order->update(['payment_match_key' => null]);
            $this->assertSame(10000, app(VoucherService::class)->quote($package, $user, $voucher->code)['discount_idr']);
        }
    }

    public function test_quota_reservation_matches_recovery_deadlines_and_missing_expiry(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$user, $product, $package, $voucher] = $this->catalog();
        foreach (['crypto' => 24, 'binance_pay' => 24, 'gopay_qris' => 72] as $method => $hours) {
            config([match ($method) {
                'crypto' => 'services.crypto_direct.recovery_hours',
                'binance_pay' => 'services.binance.pay.recovery_hours',
                default => 'services.gopay_qris.recovery_hours',
            } => $hours]);
            $order = Order::create([
                'order_id' => 'ORDER-DEADLINE-'.$method, 'user_id' => $user->id,
                'product_id' => $product->id, 'package_id' => $package->id,
                'voucher_id' => $voucher->id, 'status' => 'cancelled',
                'payment_method' => $method, 'price' => 90000, 'payment_match_key' => 'deadline-'.$method,
                'expired_at' => now()->subHours($hours)->addSecond(),
            ]);
            $this->assertQuoteRejected($package, $user, $voucher, 'already used');
            $order->update(['expired_at' => now()->subHours($hours)]);
            if ($method === 'gopay_qris') {
                $this->assertQuoteRejected($package, $user, $voucher, 'already used');
            } else {
                $this->assertSame(10000, app(VoucherService::class)->quote($package, $user, $voucher->code)['discount_idr']);
            }
            $order->update(['expired_at' => null]);
            $this->assertQuoteRejected($package, $user, $voucher, 'already used');
            $order->update(['expired_at' => now()->subHours($hours)->subSecond()]);
            $this->assertSame(10000, app(VoucherService::class)->quote($package, $user, $voucher->code)['discount_idr']);
        }
    }

    public function test_original_discounted_invoice_can_recover_but_does_not_free_another_voucher_use(): void
    {
        [$user, $product, $package, $voucher] = $this->catalog();
        $order = Order::create([
            'order_id' => 'ORDER-DISCOUNT-RECOVERY', 'user_id' => $user->id,
            'product_id' => $product->id, 'package_id' => $package->id,
            'voucher_id' => $voucher->id, 'status' => 'cancelled', 'payment_method' => 'crypto',
            'price' => 5.40001, 'payment_match_key' => 'discount-recovery', 'expired_at' => now()->addMinutes(10),
            'payment_payload' => ['type' => 'direct_crypto', 'token' => 'USDT', 'network' => 'usdtbsc'],
        ]);
        $this->assertQuoteRejected($package, $user, $voucher, 'already used');
        $this->actingAs($user)->postJson('/pay-again/'.$order->id)->assertConflict()
            ->assertJsonPath('message', 'This voucher is reserved by the original invoice during payment recovery. Verify the original payment or start a new checkout without this voucher.');
        $this->assertDatabaseCount('orders', 1);
        $this->assertNull($order->fresh()->replaced_by);
        $payment = Mockery::mock(PaymentService::class);
        $payment->shouldReceive('inspectDirectCryptoPayment')->once()->andReturn([
            'transfer' => ['tx_hash' => '0xdiscount-recovery', 'confirmed_at' => now(),
                'network' => 'usdtbsc', 'amount' => '5.40001'], 'mismatches' => [],
        ]);
        $result = (new DirectCryptoOrderVerifier($payment, app(OrderFulfillmentService::class)))->verify($order);
        $this->assertSame('paid', $result['status']);
        $this->assertQuoteRejected($package, $user, $voucher, 'already used');
        $voucher->update(['per_user_limit' => 0]);
        $this->assertSame(10000, app(VoucherService::class)->quote($package, $user, $voucher->code)['discount_idr']);
    }

    public function test_login_ingress_and_callback_reject_ambiguous_and_external_return_targets(): void
    {
        config(['app.url' => 'http://localhost']);
        $google = (new GoogleUser)->setRaw(['verified_email' => true])->map([
            'id' => 'google-security-test', 'name' => 'Buyer', 'email' => 'buyer@example.test',
        ]);
        $provider = Mockery::mock();
        $provider->shouldReceive('redirect')->andReturn(redirect()->away('https://accounts.google.test/'));
        $provider->shouldReceive('user')->andReturn($google);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        foreach (['https://evil.invalid\\@localhost/', '//evil.invalid/', '/\\evil.invalid/',
            "\t//evil.invalid/", "/licen\nses", 'javascript://localhost/x',
            'https://localhost/licenses', 'http://localhost:81/licenses',
            'http://user@localhost/licenses', ['unexpected']] as $target) {
            $this->app['session']->forget('login_redirect');
            $this->get('http://localhost/auth/google?'.http_build_query(['redirect' => $target]))
                ->assertRedirect('https://accounts.google.test/')
                ->assertSessionMissing('login_redirect');
            $this->withSession(['login_redirect' => $target])
                ->get('http://localhost/auth/google/callback?code=mock-code')->assertRedirect('/');
        }
    }

    public function test_login_preserves_valid_internal_and_same_origin_return_targets(): void
    {
        config(['app.url' => 'http://localhost']);
        $google = (new GoogleUser)->setRaw(['verified_email' => true])->map([
            'id' => 'google-security-control', 'name' => 'Buyer', 'email' => 'control@example.test',
        ]);
        $provider = Mockery::mock();
        $provider->shouldReceive('redirect')->andReturn(redirect()->away('https://accounts.google.test/'));
        $provider->shouldReceive('user')->andReturn($google);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
        foreach (['/cart', '/checkout/aurora-vn?package=2#payment', 'http://localhost/licenses'] as $target) {
            $this->get('http://localhost/auth/google?'.http_build_query(['redirect' => $target]))
                ->assertSessionHas('login_redirect', $target);
            $this->get('http://localhost/auth/google/callback?code=mock-code')->assertRedirect($target);
        }
    }

    public function test_brmods_ambiguous_responses_do_not_start_cooldown_and_can_be_retried(): void
    {
        config(['services.brmods.reset_url' => 'https://brmods.test/reset',
            'services.brmods.api_key' => 'dummy-api-key', 'services.brmods.product_slug' => 'security-product']);
        [$user, $product, $package] = $this->catalog();
        $order = Order::create(['order_id' => 'ORDER-RESET', 'user_id' => $user->id,
            'product_id' => $product->id, 'package_id' => $package->id, 'status' => 'paid']);
        $license = License::create(['user_id' => $user->id, 'product_id' => $product->id,
            'order_id' => $order->order_id, 'license_key' => '👤securityuser🔑testkey', 'duration' => '1 Day']);
        $bodies = ['<html>Login required</html>', [], ['message' => 'Unknown'], ['success' => 'maybe'],
            ['success' => true, 'error' => 'Rejected'], ['success' => true, 'status' => 'failed'],
            ['success' => true, 'ok' => false]];
        $responses = Http::fakeSequence('https://brmods.test/reset');
        foreach ($bodies as $body) {
            $responses->push($body, 200);
        }
        $responses->push(['success' => true]);
        foreach ($bodies as $body) {
            $this->travel(1)->minutes();
            $this->actingAs($user)->from('/licenses')->post(route('licenses.reset-hwid', $license))
                ->assertSessionHasErrors('license_reset');
            $this->assertSame(0, $license->resetAttempts()->whereNotNull('succeeded_at')->count());
        }
        $this->travel(1)->minutes();
        $this->actingAs($user)->from('/licenses')->post(route('licenses.reset-hwid', $license))
            ->assertSessionHas('license_reset_success');
    }

    private function assertQuoteRejected(Package $package, User $user, Voucher $voucher, string $message): void
    {
        try {
            app(VoucherService::class)->quote($package, $user, $voucher->code);
            $this->fail('Recoverable invoice must retain its voucher entitlement.');
        } catch (VoucherException $error) {
            $this->assertStringContainsString($message, $error->getMessage());
        }
    }

    private function catalog(): array
    {
        $user = User::factory()->create();
        $category = Category::create(['name' => 'Security', 'slug' => 'security']);
        $product = Product::create(['name' => 'Security Product', 'slug' => 'security-product',
            'category_id' => $category->id, 'description' => 'Regression fixture']);
        $package = Package::create(['product_id' => $product->id, 'name' => '1 Day', 'price' => 100000, 'price_usdt' => 6]);
        $voucher = Voucher::create(['code' => 'SECURITY10', 'discount_percent' => 10, 'max_discount' => 15000,
            'max_discount_usdt' => 1, 'max_discount_usdc' => 1, 'minimum_purchase' => 0,
            'usage_limit' => 20, 'per_user_limit' => 1, 'is_active' => true]);

        return [$user, $product, $package, $voucher];
    }
}
