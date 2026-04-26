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
        $testing = Product::where('name', 'Testing Payment')->firstOrFail();
        $package = Package::where('product_id', $testing->id)
            ->where('name', 'Testing')
            ->firstOrFail();

        for ($i = 1; $i <= 25; $i++) {
            LicenseStock::updateOrCreate(
                ['license_key' => sprintf('TEST-PAYMENT-%03d', $i)],
                [
                    'product_id' => $testing->id,
                    'package_id' => $package->id,
                    'is_sold' => false,
                ]
            );
        }
    }
}
