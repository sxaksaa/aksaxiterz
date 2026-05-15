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
        $dripRoot = Product::where('name', 'Drip Client Root')->firstOrFail();
        $dripNonRoot = Product::where('name', 'Drip Client Non Root')->firstOrFail();
        $fluoriteFf = Product::where('name', 'Fluorite FF')->firstOrFail();
        $fluoriteMl = Product::where('name', 'Fluorite ML')->firstOrFail();

        // AuroraVN
        $this->upsertPackage($aurora, '1 Hari', '1 Day', 20000, 1.25);
        $this->upsertPackage($aurora, '3 Hari', '3 Days', 45000, 3);
        $this->upsertPackage($aurora, '7 Hari', '7 Days', 100000, 6);
        $this->upsertPackage($aurora, '30 Hari', '30 Days', 250000, 15);

        // XG
        $this->upsertPackage($xg, '7 Hari', '7 Days', 80000, 5);

        $this->upsertPackage($dripRoot, '1 Hari', '1 Day', 30000, 1.75);
        $this->upsertPackage($dripRoot, '7 Hari', '7 Days', 100000, 6);
        $this->upsertPackage($dripRoot, '1 Month', '30 Days', 250000, 15);
        $this->removeUnusedPackages($dripRoot, ['3 Days', '15 Days']);

        $this->upsertPackage($dripNonRoot, '1 Hari', '1 Day', 25000, 1.5);
        $this->upsertPackage($dripNonRoot, '3 Hari', '3 Days', 45000, 2.75);
        $this->upsertPackage($dripNonRoot, '7 Hari', '7 Days', 80000, 4.75);
        $this->upsertPackage($dripNonRoot, '15 Hari', '15 Days', 150000, 10);
        $this->upsertPackage($dripNonRoot, '1 Month', '30 Days', 225000, 13.5);

        foreach ([$fluoriteFf, $fluoriteMl] as $product) {
            $this->upsertPackage($product, '1 Hari', '1 Day', 50000, 3);
            $this->upsertPackage($product, '7 Hari', '7 Days', 150000, 10);
            $this->upsertPackage($product, '1 Month', '30 Days', 350000, 22);
        }
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

    private function removeUnusedPackages(Product $product, array $packageNames): void
    {
        Package::where('product_id', $product->id)
            ->whereIn('name', $packageNames)
            ->whereDoesntHave('orders')
            ->each(function (Package $package): void {
                $package->licenseStocks()
                    ->where('is_sold', false)
                    ->delete();

                if (! $package->licenseStocks()->where('is_sold', true)->exists()) {
                    $package->delete();
                }
            });
    }
}
