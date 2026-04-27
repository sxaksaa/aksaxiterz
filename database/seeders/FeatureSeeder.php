<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\Product;
use Illuminate\Database\Seeder;

class FeatureSeeder extends Seeder
{
    public function run(): void
    {
        // Aurora
        $aurora = Product::where('name', 'Aurora-VN')->firstOrFail();

        $this->syncFeatures($aurora, [
            'Duration-based license access',
            'Setup guide included',
            'Customer support available',
        ]);

        // XG-Team
        $xg = Product::where('name', 'XG-Team')->firstOrFail();

        $this->syncFeatures($xg, [
            'Desktop access utility',
            'Setup tutorial included',
            'Customer support available',
        ]);

        // Testing Payment
        $testing = Product::where('name', 'Testing Payment')->firstOrFail();

        $this->syncFeatures($testing, [
            'Midtrans real payment flow test',
            'Crypto invoice flow test',
            'Dummy license delivery',
        ]);
    }

    private function syncFeatures(Product $product, array $features): void
    {
        Feature::where('product_id', $product->id)->delete();

        foreach ($features as $feature) {
            Feature::create([
                'product_id' => $product->id,
                'name' => $feature,
            ]);
        }
    }
}
