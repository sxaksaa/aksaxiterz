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

class XgTeamLicenseResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is not available in this PHP environment.');
        }

        parent::setUp();

        config([
            'services.xgteam.reset_url' => 'https://xgteam.test/reseller/1.0/',
            'services.xgteam.seller_key' => 'xg-seller-key-test',
            'services.xgteam.product_slug' => 'xg-team',
            'services.xgteam.cooldown_hours' => 48,
        ]);
    }

    public function test_my_licenses_shows_reset_action_for_paid_xg_team_licenses(): void
    {
        $user = User::factory()->create();
        $license = $this->makePaidLicense($user, 'xg-team', 'AksaXg-x5NUdJ');
        $this->makePaidLicense($user, 'another-product', 'NORMAL-LICENSE-KEY');

        $response = $this->actingAs($user)->get('/licenses');

        $response->assertOk()
            ->assertSee('Reset HWID')
            ->assertDontSee('HWID reset license:')
            ->assertSee(route('licenses.reset-hwid', $license))
            ->assertSee('once every 48 hours')
            ->assertDontSee('xg-seller-key-test');

        $this->assertSame(1, substr_count($response->getContent(), 'data-license-reset-form'));
    }

    public function test_owner_can_reset_their_paid_xg_team_license(): void
    {
        $user = User::factory()->create();
        $license = $this->makePaidLicense($user, 'xg-team', 'AksaXg-x5NUdJ');

        Http::fake([
            'https://xgteam.test/reseller/1.0/*' => Http::response([
                'success' => true,
                'message' => 'Successfully reset user HWID.',
            ]),
        ]);

        $response = $this->actingAs($user)
            ->from('/licenses')
            ->post(route('licenses.reset-hwid', $license));

        $response->assertRedirect('/licenses')
            ->assertSessionHas('license_reset_success');

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST' &&
                $request->url() === 'https://xgteam.test/reseller/1.0/' &&
                $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded') &&
                $request->data() === [
                    'key' => 'xg-seller-key-test',
                    'type' => 'resetuser',
                    'user' => 'AksaXg-x5NUdJ',
                ];
        });

        $this->assertDatabaseHas('license_resets', [
            'license_id' => $license->id,
            'user_id' => $user->id,
            'provider' => 'xgteam',
            'username' => 'AksaXg-x5NUdJ',
            'status' => 'succeeded',
            'http_status' => 200,
        ]);
        $this->assertNotNull($license->resetAttempts()->first()?->succeeded_at);
    }

    public function test_failed_xg_team_response_does_not_start_the_cooldown(): void
    {
        $user = User::factory()->create();
        $license = $this->makePaidLicense($user, 'xg-team', 'AksaXg-x5NUdJ');

        Http::fake([
            'https://xgteam.test/reseller/1.0/*' => Http::response([
                'success' => false,
                'message' => 'User HWID is already reset.',
            ]),
        ]);

        $this->actingAs($user)
            ->from('/licenses')
            ->post(route('licenses.reset-hwid', $license))
            ->assertSessionHasErrors('license_reset');

        $this->assertDatabaseHas('license_resets', [
            'license_id' => $license->id,
            'provider' => 'xgteam',
            'status' => 'failed',
            'provider_message' => 'User HWID is already reset.',
        ]);
        $this->assertNull($license->resetAttempts()->first()?->succeeded_at);
    }

    public function test_unconfirmed_responses_never_start_the_cooldown(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $license = $this->makePaidLicense($user, 'xg-team', 'AksaXg-x5NUdJ');

        Http::fakeSequence()
            ->push('<html>Login required</html>', 200)
            ->push(['message' => 'Unknown response'], 200)
            ->push(['success' => 'false'], 200)
            ->push(['success' => true], 500);

        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($user)
                ->from('/licenses')
                ->post(route('licenses.reset-hwid', $license))
                ->assertSessionHasErrors('license_reset');
        }

        $this->assertSame(4, $license->resetAttempts()->where('status', 'failed')->count());
        $this->assertSame(0, $license->resetAttempts()->whereNotNull('succeeded_at')->count());
    }

    public function test_old_secret_does_not_enable_the_new_api(): void
    {
        config([
            'services.xgteam.seller_key' => '',
            'services.xgteam.secret' => 'legacy-secret',
        ]);
        Http::fake();
        $user = User::factory()->create();
        $license = $this->makePaidLicense($user, 'xg-team', 'AksaXg-x5NUdJ');

        $this->actingAs($user)
            ->post(route('licenses.reset-hwid', $license))
            ->assertSessionHasErrors('license_reset');

        Http::assertNothingSent();
        $this->assertDatabaseCount('license_resets', 0);
    }

    private function makePaidLicense(
        User $user,
        string $productSlug,
        string $credential,
    ): License {
        $category = Category::firstOrCreate(
            ['slug' => 'pc'],
            ['name' => 'PC'],
        );
        $product = Product::firstOrCreate(
            ['slug' => $productSlug],
            [
                'category_id' => $category->id,
                'name' => str($productSlug)->replace('-', ' ')->title()->toString(),
                'description' => 'Test product.',
            ],
        );
        $package = Package::firstOrCreate(
            [
                'product_id' => $product->id,
                'name' => '1 Day',
            ],
            [
                'price' => 35000,
                'price_usdt' => 2,
            ],
        );
        $order = Order::create([
            'order_id' => 'ORDER-XGTEAM-'.strtoupper(uniqid()),
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
            'license_key' => $credential,
            'duration' => '1 Day',
            'order_id' => $order->order_id,
        ]);
    }
}
