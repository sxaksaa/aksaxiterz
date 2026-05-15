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

        $this->syncFeatures(Product::where('name', 'Drip Client Root')->firstOrFail(), [
            'Android root client access',
            'Duration-based license delivery',
            'Setup support available',
        ]);

        $this->syncFeatures(Product::where('name', 'Drip Client Non Root')->firstOrFail(), [
            'Android non-root client access',
            'Duration-based license delivery',
            'Setup support available',
        ]);

        $this->syncFeatures(Product::where('name', 'Fluorite FF')->firstOrFail(), [
            'iOS Fluorite FF access',
            'Duration-based license delivery',
            'Setup support available',
        ]);

        $this->syncFeatures(Product::where('name', 'Fluorite ML')->firstOrFail(), [
            'iOS Fluorite ML access',
            'Duration-based license delivery',
            'Setup support available',
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
