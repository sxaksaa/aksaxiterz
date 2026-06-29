<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\License;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

class BrModsLicenseResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is not available in this PHP environment.');
        }

        parent::setUp();

        config([
            'services.brmods.reset_url' => 'https://brmods.test/api/reset.php',
            'services.brmods.api_key' => 'secret-test-api-key',
            'services.brmods.product_slug' => 'br-mods-pc',
            'services.brmods.cooldown_hours' => 24,
        ]);
    }

    public function test_my_licenses_shows_reset_action_only_for_paid_br_mods_licenses(): void
    {
        $user = User::factory()->create();
        $brLicense = $this->makePaidLicense($user, 'br-mods-pc', '👤v9xwndt9🔑4zmh');
        $this->makePaidLicense($user, 'another-product', 'NORMAL-LICENSE-KEY');

        $response = $this->actingAs($user)->get('/licenses');

        $response->assertOk()
            ->assertSee('Reset HWID')
            ->assertSee('HWID reset username: v9xwndt9')
            ->assertSee(route('licenses.reset-hwid', $brLicense))
            ->assertSee('once every 24 hours')
            ->assertDontSee('secret-test-api-key');

        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'data-brmods-reset-form'));
        $this->assertLessThan(
            strpos($html, 'data-copy-license="'.$brLicense->id.'"'),
            strpos($html, 'data-brmods-reset-form'),
            'The Reset HWID action should render to the left of Copy.',
        );
    }

    public function test_owner_can_reset_their_paid_br_mods_license(): void
    {
        $user = User::factory()->create();
        $license = $this->makePaidLicense($user, 'br-mods-pc', '👤v9xwndt9🔑4zmh');

        Http::fake([
            'https://brmods.test/api/reset.php' => Http::response([
                'success' => true,
                'message' => 'Usuario resetado com sucesso',
            ]),
        ]);

        $response = $this->actingAs($user)
            ->from('/licenses')
            ->post(route('licenses.reset-hwid', $license));

        $response->assertRedirect('/licenses')
            ->assertSessionHas('license_reset_success');

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST' &&
                $request->url() === 'https://brmods.test/api/reset.php' &&
                ($request->header('X-API-Key')[0] ?? null) === 'secret-test-api-key' &&
                $request['username'] === 'v9xwndt9';
        });

        $this->assertDatabaseHas('license_resets', [
            'license_id' => $license->id,
            'user_id' => $user->id,
            'provider' => 'brmods',
            'username' => 'v9xwndt9',
            'status' => 'succeeded',
            'http_status' => 200,
        ]);
        $this->assertNotNull($license->resetAttempts()->first()?->succeeded_at);
    }

    public function test_successful_reset_starts_a_24_hour_cooldown_for_that_license_only(): void
    {
        $this->travelTo(now()->startOfMinute());

        $user = User::factory()->create();
        $firstLicense = $this->makePaidLicense($user, 'br-mods-pc', '👤firstuser🔑firstpass');
        $secondLicense = $this->makePaidLicense($user, 'br-mods-pc', '👤seconduser🔑secondpass');

        Http::fake([
            'https://brmods.test/api/reset.php' => Http::response(['status' => 'success']),
        ]);

        $this->actingAs($user)
            ->from('/licenses')
            ->post(route('licenses.reset-hwid', $firstLicense))
            ->assertSessionHas('license_reset_success');

        $this->actingAs($user)
            ->from('/licenses')
            ->post(route('licenses.reset-hwid', $firstLicense))
            ->assertSessionHasErrors('license_reset');

        Http::assertSentCount(1);
        $this->assertSame(1, $firstLicense->resetAttempts()->count());

        $page = $this->actingAs($user)->get('/licenses');
        $page->assertOk()
            ->assertSee('Reset in 24h')
            ->assertSee(route('licenses.reset-hwid', $secondLicense));

        $this->travel(24)->hours();

        $this->actingAs($user)
            ->from('/licenses')
            ->post(route('licenses.reset-hwid', $firstLicense))
            ->assertSessionHas('license_reset_success');

        Http::assertSentCount(2);
    }

    public function test_failed_provider_call_does_not_start_the_cooldown(): void
    {
        $user = User::factory()->create();
        $license = $this->makePaidLicense($user, 'br-mods-pc', '👤retryuser🔑retrypass');

        Http::fakeSequence('https://brmods.test/api/reset.php')
            ->push([
                'success' => false,
                'message' => 'Reset rejected',
            ])
            ->push(['success' => true]);

        $this->actingAs($user)
            ->from('/licenses')
            ->post(route('licenses.reset-hwid', $license))
            ->assertSessionHasErrors('license_reset');

        $this->assertDatabaseHas('license_resets', [
            'license_id' => $license->id,
            'status' => 'failed',
            'provider_message' => 'Reset rejected',
        ]);
        $this->assertNull($license->resetAttempts()->first()?->succeeded_at);

        $this->actingAs($user)
            ->from('/licenses')
            ->post(route('licenses.reset-hwid', $license))
            ->assertSessionHas('license_reset_success');

        $this->assertSame(2, $license->resetAttempts()->count());
        $this->assertSame(1, $license->resetAttempts()->where('status', 'succeeded')->count());
    }

    public function test_user_cannot_reset_another_customers_license(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $license = $this->makePaidLicense($owner, 'br-mods-pc', '👤owneruser🔑ownerpass');

        Http::fake();

        $this->actingAs($attacker)
            ->post(route('licenses.reset-hwid', $license))
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('license_resets', 0);
    }

    public function test_non_br_product_cannot_use_the_reset_endpoint(): void
    {
        $user = User::factory()->create();
        $license = $this->makePaidLicense($user, 'aurora-vn', '👤notbr🔑password');

        Http::fake();

        $this->actingAs($user)
            ->post(route('licenses.reset-hwid', $license))
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('license_resets', 0);
    }

    public function test_invalid_br_credential_format_is_not_sent_to_the_provider(): void
    {
        $user = User::factory()->create();
        $license = $this->makePaidLicense($user, 'br-mods-pc', 'v9xwndt9:4zmh');

        Http::fake();

        $this->actingAs($user)
            ->from('/licenses')
            ->post(route('licenses.reset-hwid', $license))
            ->assertSessionHasErrors('license_reset');

        Http::assertNothingSent();
        $this->assertDatabaseCount('license_resets', 0);
    }

    public function test_admin_stock_import_requires_the_br_mods_credential_format(): void
    {
        config(['admin.emails' => ['admin@example.com']]);

        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $category = Category::firstOrCreate(['slug' => 'pc'], ['name' => 'PC']);
        $product = Product::firstOrCreate(
            ['slug' => 'br-mods-pc'],
            [
                'category_id' => $category->id,
                'name' => 'BR Mods PC',
                'description' => 'Panel for PC',
            ],
        );
        $package = Package::create([
            'product_id' => $product->id,
            'name' => '1 Day',
            'price' => 35000,
            'price_usdt' => 2,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.license-stocks.index'))
            ->post(route('admin.license-stocks.store'), [
                'product_id' => $product->id,
                'package_id' => $package->id,
                'license_keys' => 'invalid:credential',
            ])
            ->assertSessionHasErrors('license_keys');

        $this->assertDatabaseCount('license_stocks', 0);

        $this->actingAs($admin)
            ->post(route('admin.license-stocks.store'), [
                'product_id' => $product->id,
                'package_id' => $package->id,
                'license_keys' => '👤stockuser🔑stockpass',
            ])
            ->assertSessionHasNoErrors();

        $stock = LicenseStock::sole();

        $this->assertSame('👤stockuser🔑stockpass', $stock->license_key);
        $stockPage = $this->actingAs($admin)->get(route('admin.license-stocks.index'));
        $stockPage->assertOk()
            ->assertSee('data-stock-copy="'.$stock->id.'"', false)
            ->assertSee('data-copy-value="👤stockuser🔑stockpass"', false);
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
            'order_id' => 'ORDER-BRMODS-'.strtoupper(uniqid()),
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
