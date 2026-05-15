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
        $this->seedPackageStock('Aurora-VN', '3 Days', 'AURORA-3D', 20);
        $this->seedPackageStock('Aurora-VN', '7 Days', 'AURORA-7D', 20);
        $this->seedPackageStock('Aurora-VN', '30 Days', 'AURORA-30D', 20);
        $this->seedPackageStock('XG-Team', '7 Days', 'XG-7D', 20);

        $this->purgeUnsoldStockPrefix('Drip Client Root', '30 Days', 'DRIP-ROOT-1M');
        $this->purgeUnsoldStockPrefix('Drip Client Non Root', '30 Days', 'DRIP-NONROOT-1M');
        $this->purgeUnsoldStockPrefix('Fluorite FF', '30 Days', 'FLUORITE-FF-1M');
        $this->purgeUnsoldStockPrefix('Fluorite ML', '30 Days', 'FLUORITE-ML-1M');

        $this->seedPackageStock('Drip Client Root', '1 Day', 'DRIP-ROOT-1D', 20);
        $this->seedPackageStock('Drip Client Root', '7 Days', 'DRIP-ROOT-7D', 20);
        $this->seedPackageStock('Drip Client Root', '30 Days', 'DRIP-ROOT-30D', 20);

        $this->seedPackageStock('Drip Client Non Root', '1 Day', 'DRIP-NONROOT-1D', 20);
        $this->seedPackageStock('Drip Client Non Root', '3 Days', 'DRIP-NONROOT-3D', 20);
        $this->seedPackageStock('Drip Client Non Root', '7 Days', 'DRIP-NONROOT-7D', 20);
        $this->seedPackageStock('Drip Client Non Root', '15 Days', 'DRIP-NONROOT-15D', 20);
        $this->seedPackageStock('Drip Client Non Root', '30 Days', 'DRIP-NONROOT-30D', 20);

        foreach ([
            'Fluorite FF' => 'FLUORITE-FF',
            'Fluorite ML' => 'FLUORITE-ML',
        ] as $productName => $prefix) {
            $this->seedPackageStock($productName, '1 Day', $prefix.'-1D', 20);
            $this->seedPackageStock($productName, '7 Days', $prefix.'-7D', 20);
            $this->seedPackageStock($productName, '30 Days', $prefix.'-30D', 20);
        }
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

    private function purgeUnsoldStockPrefix(string $productName, string $packageName, string $prefix): void
    {
        $product = Product::where('name', $productName)->first();

        if (! $product) {
            return;
        }

        $package = Package::where('product_id', $product->id)
            ->where('name', $packageName)
            ->first();

        if (! $package) {
            return;
        }

        LicenseStock::where('product_id', $product->id)
            ->where('package_id', $package->id)
            ->where('license_key', 'like', $prefix.'-%')
            ->where('is_sold', false)
            ->delete();
    }
}
