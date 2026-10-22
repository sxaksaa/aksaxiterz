<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminLicenseStockMutationTest extends TestCase
{
    use RefreshDatabase;

    public static function protectedMutations(): array
    {
        return [
            'edit after reservation' => ['patch', 'reserved'],
            'edit after sale' => ['patch', 'sold'],
            'delete after reservation' => ['delete', 'reserved'],
            'delete after sale' => ['delete', 'sold'],
        ];
    }

    #[DataProvider('protectedMutations')]
    public function test_mutation_rechecks_current_stock_after_route_binding(string $method, string $state): void
    {
        [$admin, $stock, $order] = $this->fixture();
        $armed = true;
        $outerTransactionLevel = DB::transactionLevel();
        $lockedReads = 0;
        LicenseStock::retrieved(function (LicenseStock $loaded) use ($stock, $order, $state, &$armed, &$lockedReads, $outerTransactionLevel): void {
            if ($loaded->id !== $stock->id) {
                return;
            }
            if ($armed) {
                $armed = false;
                // Simulate checkout committing immediately after route binding read its snapshot.
                LicenseStock::whereKey($stock->id)->update($state === 'sold'
                    ? ['is_sold' => true]
                    : ['reserved_order_id' => $order->id, 'reserved_until' => now()->addMinutes(10)]);
            } elseif (DB::transactionLevel() > $outerTransactionLevel) {
                $lockedReads++;
            }
        });

        try {
            $response = $this->actingAs($admin)->from('/admin/license-stocks')
                ->{$method}(route('admin.license-stocks.'.($method === 'patch' ? 'update' : 'destroy'), $stock), [
                    'license_key' => 'EDITED-KEY', 'product_id' => $stock->product_id, 'package_id' => $stock->package_id,
                ]);
            $response->assertRedirect('/admin/license-stocks')->assertSessionHasErrors('license_key');
            $this->assertFalse($armed);
            $this->assertGreaterThan(0, $lockedReads, 'Mutation must reload stock inside its own transaction.');
            $this->assertDatabaseHas('license_stocks', ['id' => $stock->id, 'license_key' => 'ORIGINAL-KEY']);
            $this->assertSame($state === 'sold', $stock->fresh()->is_sold);
            if ($state === 'reserved') {
                $this->assertSame($order->id, (int) $stock->fresh()->reserved_order_id);
            }
        } finally {
            $armed = false;
        }
    }

    public function test_admin_can_still_edit_and_delete_available_stock(): void
    {
        [$admin, $stock] = $this->fixture();
        $this->actingAs($admin)->patch(route('admin.license-stocks.update', $stock), [
            'license_key' => 'EDITED-KEY', 'product_id' => $stock->product_id, 'package_id' => $stock->package_id,
        ])->assertRedirect(route('admin.license-stocks.index'))->assertSessionHasNoErrors();
        $this->assertSame('EDITED-KEY', $stock->fresh()->license_key);
        $this->from('/admin/license-stocks')->delete(route('admin.license-stocks.destroy', $stock))
            ->assertRedirect('/admin/license-stocks')->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('license_stocks', ['id' => $stock->id]);
    }

    public function test_cancelled_reservation_does_not_block_available_stock_management(): void
    {
        [$admin, $stock, $order] = $this->fixture();
        $order->update(['status' => 'cancelled']);
        $stock->update(['reserved_order_id' => $order->id, 'reserved_until' => now()->addMinutes(10)]);
        $this->actingAs($admin)->patch(route('admin.license-stocks.update', $stock), [
            'license_key' => 'RELEASED-KEY', 'product_id' => $stock->product_id, 'package_id' => $stock->package_id,
        ])->assertSessionHasNoErrors();
        $this->assertSame('RELEASED-KEY', $stock->fresh()->license_key);
        $this->delete(route('admin.license-stocks.destroy', $stock))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('license_stocks', ['id' => $stock->id]);
    }

    private function fixture(): array
    {
        config(['admin.emails' => ['stock-admin@example.test']]);
        $admin = User::factory()->create(['email' => 'stock-admin@example.test']);
        $category = Category::firstOrCreate(['slug' => 'pc'], ['name' => 'PC']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Stock Product',
            'slug' => 'stock-product', 'description' => 'Stock mutation fixture']);
        $package = Package::create(['product_id' => $product->id, 'name' => '1 Day', 'price' => 10000]);
        $order = Order::create(['user_id' => User::factory()->create()->id, 'order_id' => 'ORDER-STOCK-RACE',
            'product_id' => $product->id, 'package_id' => $package->id, 'status' => 'pending',
            'payment_method' => 'gopay_qris', 'expired_at' => now()->addMinutes(10)]);
        $stock = LicenseStock::create(['product_id' => $product->id, 'package_id' => $package->id,
            'license_key' => 'ORIGINAL-KEY', 'is_sold' => false]);

        return [$admin, $stock, $order];
    }
}
