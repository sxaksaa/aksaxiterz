<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $pc = Category::where('slug', 'pc')->firstOrFail();
        $android = Category::where('slug', 'android')->firstOrFail();
        $ios = Category::where('slug', 'ios')->firstOrFail();

        foreach ([
            [
                'name' => 'Aurora-VN',
                'description' => 'Licensed desktop utility package with setup support and duration-based access.',
                'category_id' => $pc->id,
            ],
            [
                'name' => 'XG-Team',
                'description' => 'Desktop utility license with setup guidance and access support.',
                'category_id' => $pc->id,
            ],
            [
                'name' => 'Drip Client Root',
                'description' => 'Android root client license with setup support and duration-based access.',
                'category_id' => $android->id,
            ],
            [
                'name' => 'Drip Client Non Root',
                'description' => 'Android non-root client license with setup support and duration-based access.',
                'category_id' => $android->id,
            ],
            [
                'name' => 'Fluorite FF',
                'description' => 'iOS Fluorite license for FF with setup guidance and duration-based access.',
                'category_id' => $ios->id,
            ],
            [
                'name' => 'Fluorite ML',
                'description' => 'iOS Fluorite license for ML with setup guidance and duration-based access.',
                'category_id' => $ios->id,
            ],
        ] as $product) {
            Product::updateOrCreate(
                ['name' => $product['name']],
                [
                    'slug' => Str::slug($product['name']),
                    'description' => $product['description'],
                    'category_id' => $product['category_id'],
                ]
            );
        }
    }
}
