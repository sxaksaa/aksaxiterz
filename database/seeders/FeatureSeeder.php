<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\Feature;

class FeatureSeeder extends Seeder
{
    public function run(): void
    {
        // Aurora
        $aurora = Product::where('name', 'Aurora-VN')->firstOrFail();

        $auroraFeatures = [
            'Aimbot',
            'ESP Enemy',
            'ESP Item',
            'Pull Player',
            'etc'
        ];

        foreach ($auroraFeatures as $f) {
            Feature::updateOrCreate(
                [
                    'product_id' => $aurora->id,
                    'name' => $f
                ]
            );
        }

        // XG-Team
        $xg = Product::where('name', 'XG-Team')->firstOrFail();

        $xgFeatures = [
            'Remove Logo PC',
            '120 FPS Unlocked On Free Fire Setting'
        ];

        foreach ($xgFeatures as $f) {
            Feature::updateOrCreate(
                [
                    'product_id' => $xg->id,
                    'name' => $f
                ]
            );
        }

        // Testing Payment
        $testing = Product::where('name', 'Testing Payment')->firstOrFail();

        $testingFeatures = [
            'Midtrans real payment flow test',
            'Crypto invoice flow test',
            'Dummy license delivery'
        ];

        foreach ($testingFeatures as $f) {
            Feature::updateOrCreate(
                [
                    'product_id' => $testing->id,
                    'name' => $f
                ]
            );
        }
    }
}
