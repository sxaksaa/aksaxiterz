<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\Package;

class PackageSeeder extends Seeder
{
    public function run(): void
    {
        $aurora = Product::where('name', 'AuroraVN')->first();
        $xg = Product::where('name', 'XG')->first();

        // 🔥 AuroraVN
        Package::updateOrCreate(
            ['product_id' => $aurora->id, 'name' => '1 Hari'],
            ['price' => 50000, 'price_usdt' => 3]
        );

        Package::updateOrCreate(
            ['product_id' => $aurora->id, 'name' => '7 Hari'],
            ['price' => 150000, 'price_usdt' => 8]
        );

        Package::updateOrCreate(
            ['product_id' => $aurora->id, 'name' => '30 Hari'],
            ['price' => 300000, 'price_usdt' => 15]
        );

        // 🔥 XG
        Package::updateOrCreate(
            ['product_id' => $xg->id, 'name' => '1 Hari'],
            ['price' => 30000, 'price_usdt' => 2]
        );

        Package::updateOrCreate(
            ['product_id' => $xg->id, 'name' => '7 Hari'],
            ['price' => 100000, 'price_usdt' => 6]
        );

        Package::updateOrCreate(
            ['product_id' => $xg->id, 'name' => '30 Hari'],
            ['price' => 200000, 'price_usdt' => 12]
        );
    }
}