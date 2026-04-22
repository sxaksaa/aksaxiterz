<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\Feature;

class FeatureSeeder extends Seeder
{
    public function run(): void
    {
        $aurora = Product::where('name', 'AuroraVN')->first();

        $features = [
            'Smooth ESP',
            'Aimbot',
            'Silent aim',
            'FOV',
            '120 FPS'
        ];

        foreach ($features as $f) {
            Feature::updateOrCreate(
                [
                    'product_id' => $aurora->id,
                    'name' => $f
                ]
            );
        }
    }
}