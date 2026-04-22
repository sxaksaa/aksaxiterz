<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\Category;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $category = Category::where('slug', 'free-fire')->first();

        $product1 = Product::updateOrCreate(
            ['name' => 'AuroraVN'],
            [
                'description' => 'Aurora access',
                'category_id' => $category->id,
                'price' => 0 // 🔥 TAMBAH INI
            ]
        );

        $product2 = Product::updateOrCreate(
            ['name' => 'XG'],
            [
                'description' => 'XG access',
                'category_id' => $category->id,
                'price' => 0 // 🔥 TAMBAH INI
            ]
        );
    }
}
