<?php

namespace Database\Seeders;

use App\Models\Package;
use App\Models\Product;
use Illuminate\Database\Seeder;

class PackageSeeder extends Seeder
{
    public function run(): void
    {
        $aurora = Product::where('name', 'Aurora-VN')->firstOrFail();
        $xg = Product::where('name', 'XG-Team')->firstOrFail();

        // AuroraVN
        $this->upsertPackage($aurora, '1 Hari', '1 Day', 20000, 1.25);
        $this->upsertPackage($aurora, '7 Hari', '7 Days', 100000, 6);
        $this->upsertPackage($aurora, '30 Hari', '30 Days', 250000, 15);

        // XG
        $this->upsertPackage($xg, '7 Hari', '7 Days', 80000, 5);
    }

    private function upsertPackage(Product $product, string $oldName, string $name, int $price, float $priceUsdt): void
    {
        $package = Package::where('product_id', $product->id)
            ->whereIn('name', [$oldName, $name])
            ->first();

        if ($package) {
            $package->update([
                'name' => $name,
                'price' => $price,
                'price_usdt' => $priceUsdt,
            ]);

            return;
        }

        Package::create([
            'product_id' => $product->id,
            'name' => $name,
            'price' => $price,
            'price_usdt' => $priceUsdt,
        ]);
    }
}
