<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\Package;

class PackageSeeder extends Seeder
{
    public function run(): void
    {
        $aurora = Product::where('name', 'Aurora-VN')->first();
        $xg = Product::where('name', 'XG-Team')->first();

        // 🔥 AuroraVN
        Package::updateOrCreate(
            ['product_id' => $aurora->id, 'name' => '1 Hari'],
            ['price' => 20000, 'price_usdt' => 1.25]
        );

        Package::updateOrCreate(
            ['product_id' => $aurora->id, 'name' => '7 Hari'],
            ['price' => 100000, 'price_usdt' => 6]
        );

        Package::updateOrCreate(
            ['product_id' => $aurora->id, 'name' => '30 Hari'],
            ['price' => 250000, 'price_usdt' => 15]
        );

        // 🔥 XG
        Package::updateOrCreate(
            ['product_id' => $xg->id, 'name' => '7 Hari'],
            ['price' => 80000, 'price_usdt' => 5]
        );
    }
}
