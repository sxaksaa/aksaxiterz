<?php

namespace Database\Seeders;

use App\Models\LicenseStock;
use App\Models\Package;
use App\Models\Product;
use Illuminate\Database\Seeder;

class LicenseStockSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPackageStock('Aurora-VN', '1 Day', 'AURORA-1D', 20);
        $this->seedPackageStock('Aurora-VN', '7 Days', 'AURORA-7D', 20);
        $this->seedPackageStock('Aurora-VN', '30 Days', 'AURORA-30D', 20);
        $this->seedPackageStock('XG-Team', '7 Days', 'XG-7D', 20);
    }

    private function seedPackageStock(string $productName, string $packageName, string $prefix, int $count): void
    {
        $product = Product::where('name', $productName)->firstOrFail();
        $package = Package::where('product_id', $product->id)
            ->where('name', $packageName)
            ->firstOrFail();

        for ($i = 1; $i <= $count; $i++) {
            $stock = LicenseStock::firstOrCreate(
                ['license_key' => sprintf('%s-%03d', $prefix, $i)],
                [
                    'product_id' => $product->id,
                    'package_id' => $package->id,
                    'is_sold' => false,
                ]
            );

            if ((int) $stock->product_id !== (int) $product->id || (int) $stock->package_id !== (int) $package->id) {
                $stock->update([
                    'product_id' => $product->id,
                    'package_id' => $package->id,
                ]);
            }
        }
    }
}
