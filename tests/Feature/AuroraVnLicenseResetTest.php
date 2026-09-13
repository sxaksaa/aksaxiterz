<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\License;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

class AuroraVnLicenseResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is not available in this PHP environment.');
        }

        parent::setUp();

        config([
            'services.aurora_vn.reset_url' => 'https://aurora-license.test/api/reseller?action=resethwid',
            'services.aurora_vn.api_key' => 'aurora-test-secret',
            'services.aurora_vn.product_slug' => 'aurora-vn',
            'services.aurora_vn.cooldown_hours' => 24,
        ]);
        Http::preventStrayRequests();
    }

    public function test_paid_aurora_license_shows_reset_action_without_exposing_api_key(): void
    {
        $user = User::factory()->create();
        $license = $this->makeLicense($user);
        $this->makeLicense($user, 'another-product');

        $response = $this->actingAs($user)->get('/licenses');

        $response->assertOk()
            ->assertSee(route('licenses.reset-hwid', $license))
            ->assertSee('Reset HWID')
            ->assertSee('once every 24 hours')
            ->assertDontSee('aurora-test-secret');
        $this->assertSame(1, substr_count($response->getContent(), 'data-license-reset-form'));
    }

    public function test_owner_can_reset_paid_aurora_license_with_documented_json_request(): void
    {
        $user = User::factory()->create();
        $license = $this->makeLicense($user);

        Http::fake([
            'aurora-license.test/*' => Http::response([
                'success' => true,
                'message' => 'HWID reset for key [AVN-ABCD-EFGH-IJKL-MNOP] successful!',
            ]),
        ]);

        $this->actingAs($user)->from('/licenses')
            ->post(route('licenses.reset-hwid', $license))
            ->assertRedirect('/licenses')
            ->assertSessionHas('license_reset_success');

        Http::assertSent(fn ($request): bool => $request->method() === 'POST' &&
            $request->url() === 'https://aurora-license.test/api/reseller?action=resethwid' &&
            ($request->header('x-api-key')[0] ?? null) === 'aurora-test-secret' &&
            $request->hasHeader('Content-Type', 'application/json') &&
            $request->data() === ['license_key' => 'AVN-ABCD-EFGH-IJKL-MNOP']
        );

        $this->assertDatabaseHas('license_resets', [
            'license_id' => $license->id,
            'provider' => 'aurora_vn',
            'username' => '••••MNOP',
            'status' => 'succeeded',
            'http_status' => 200,
        ]);
        $this->assertNotNull($license->resetAttempts()->first()?->succeeded_at);
        $this->assertStringNotContainsString(
            'AVN-ABCD-EFGH-IJKL-MNOP',
            (string) $license->resetAttempts()->first()?->provider_message,
        );

        $this->actingAs($user)->get('/licenses')
            ->assertOk()
            ->assertSee('Reset in 24h')
            ->assertDontSee('data-license-reset-form');

        $this->actingAs($user)->post(route('licenses.reset-hwid', $license))
            ->assertSessionHasErrors('license_reset');
        Http::assertSentCount(1);
    }

    public function test_other_user_unpaid_and_unconfigured_licenses_never_call_aurora(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $license = $this->makeLicense($owner);

        $this->actingAs(User::factory()->create())
            ->post(route('licenses.reset-hwid', $license))
            ->assertNotFound();

        $license->order()->update(['status' => 'pending']);
        $this->actingAs($owner)->post(route('licenses.reset-hwid', $license))
            ->assertSessionHasErrors('license_reset');

        $license->order()->update(['status' => 'paid']);
        config(['services.aurora_vn.api_key' => '']);
        $this->actingAs($owner)->post(route('licenses.reset-hwid', $license))
            ->assertSessionHasErrors('license_reset');

        Http::assertNothingSent();
        $this->assertDatabaseCount('license_resets', 0);
    }

    public function test_failed_or_unconfirmed_responses_do_not_start_cooldown(): void
    {
        $user = User::factory()->create();
        $license = $this->makeLicense($user);
        Http::fakeSequence()
            ->push(['success' => false, 'message' => 'Key not found'], 200)
            ->push('<html>Login required</html>', 200)
            ->push(['message' => 'Unknown response'], 200)
            ->push(['success' => true], 500);

        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($user)->from('/licenses')
                ->post(route('licenses.reset-hwid', $license))
                ->assertSessionHasErrors('license_reset');
        }

        $this->assertSame(4, $license->resetAttempts()->where('status', 'failed')->count());
        $this->assertSame(0, $license->resetAttempts()->whereNotNull('succeeded_at')->count());
    }

    private function makeLicense(User $user, string $productSlug = 'aurora-vn'): License
    {
        $category = Category::firstOrCreate(['slug' => 'pc'], ['name' => 'PC']);
        $product = Product::firstOrCreate(['slug' => $productSlug], [
            'category_id' => $category->id,
            'name' => 'Aurora VN',
            'description' => 'Test product.',
        ]);
        $package = Package::firstOrCreate([
            'product_id' => $product->id,
            'name' => '1 Day',
        ], [
            'price' => 35000,
            'price_usdt' => 2,
        ]);
        $order = Order::create([
            'order_id' => 'ORDER-AURORA-'.strtoupper(uniqid()),
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'status' => 'paid',
            'paid_at' => now(),
            'payment_method' => 'pakasir',
            'price' => 35000,
            'quantity' => 1,
        ]);

        return License::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'license_key' => 'AVN-ABCD-EFGH-IJKL-MNOP',
            'duration' => '1 Day',
            'order_id' => $order->order_id,
        ]);
    }
}
