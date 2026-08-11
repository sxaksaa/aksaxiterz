<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\Category;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

class AdminActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is not available in this PHP environment.');
        }

        parent::setUp();
        config(['admin.emails' => ['admin@example.com']]);
    }

    public function test_successful_admin_change_is_recorded_and_filterable(): void
    {
        [$admin, $category, $product] = $this->catalogFixture();

        $this->actingAs($admin)
            ->withHeader('User-Agent', 'Aksa Admin Test Browser')
            ->patch(route('admin.products.update', $product), [
                'category_id' => $category->id,
                'name' => 'Hidden Product',
                'description' => 'Updated product description.',
                'status' => Product::STATUS_UPDATING,
                'is_visible' => '0',
            ])
            ->assertRedirect();

        $log = AdminActivityLog::sole();

        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('admin.products.update', $log->action);
        $this->assertSame('catalog', $log->section);
        $this->assertSame('Hidden Product', $log->subject_label);
        $this->assertSame('Status: updating · Visibility: hidden', $log->details);
        $this->assertSame('Aksa Admin Test Browser', $log->user_agent);

        $this->actingAs($admin)
            ->get(route('admin.activity.index', ['section' => 'catalog', 'period' => 'today']))
            ->assertOk()
            ->assertSee('Updated product')
            ->assertSee('Hidden Product')
            ->assertSee('Visibility: hidden');
    }

    public function test_failed_admin_change_is_not_recorded_and_page_is_admin_only(): void
    {
        [$admin, $category] = $this->catalogFixture();
        $customer = User::factory()->create(['email' => 'customer@example.com']);

        $this->actingAs($admin)
            ->delete(route('admin.categories.destroy', $category))
            ->assertSessionHasErrors('category');

        $this->assertDatabaseCount('admin_activity_logs', 0);

        $this->actingAs($customer)
            ->get(route('admin.activity.index'))
            ->assertNotFound();
    }

    public function test_license_keys_are_never_copied_into_activity_log(): void
    {
        [$admin, , $product, $package] = $this->catalogFixture();
        $secretKeys = "SECRET-LICENSE-ONE\nSECRET-LICENSE-TWO";

        $this->actingAs($admin)
            ->post(route('admin.license-stocks.store'), [
                'product_id' => $product->id,
                'package_id' => $package->id,
                'license_keys' => $secretKeys,
            ])
            ->assertRedirect();

        $log = AdminActivityLog::sole();
        $serializedLog = json_encode($log->toArray(), JSON_THROW_ON_ERROR);

        $this->assertSame('admin.license-stocks.store', $log->action);
        $this->assertSame('2 keys · Audit Product · 1 Day', $log->details);
        $this->assertStringNotContainsString('SECRET-LICENSE-ONE', $serializedLog);
        $this->assertStringNotContainsString('SECRET-LICENSE-TWO', $serializedLog);

        $log->update([
            'details' => '2 key(s) · Product #'.$product->id.' · Package #'.$package->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.activity.index'))
            ->assertOk()
            ->assertSee('2 key(s) · Audit Product · 1 Day')
            ->assertDontSee('Product #'.$product->id)
            ->assertDontSee('Package #'.$package->id);

        $this->actingAs($admin)
            ->get(route('admin.activity.index', ['search' => 'Audit Product']))
            ->assertOk()
            ->assertSee('2 key(s) · Audit Product · 1 Day');

        $this->actingAs($admin)
            ->get(route('admin.activity.index', ['search' => '1 Day']))
            ->assertOk()
            ->assertSee('2 key(s) · Audit Product · 1 Day');
    }

    private function catalogFixture(): array
    {
        $admin = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
        ]);

        $category = Category::create([
            'name' => 'PC',
            'slug' => 'pc-'.uniqid(),
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Audit Product',
            'slug' => 'audit-product-'.uniqid(),
            'status' => Product::STATUS_READY,
            'is_visible' => true,
            'description' => 'Audit product description.',
        ]);

        $package = Package::create([
            'product_id' => $product->id,
            'name' => '1 Day',
            'price' => 20000,
            'price_usdt' => 1.25,
        ]);

        return [$admin, $category, $product, $package];
    }
}
