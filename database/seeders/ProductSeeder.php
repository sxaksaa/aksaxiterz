<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $category = Category::where('slug', 'digital-tools')->firstOrFail();

        Product::updateOrCreate(
            ['name' => 'Aurora-VN'],
            [
                'description' => 'Licensed desktop utility package with setup support and duration-based access.',
                'category_id' => $category->id,
            ]
        );

        Product::updateOrCreate(
            ['name' => 'XG-Team'],
            [
                'description' => 'Desktop utility license with setup guidance and access support.',
                'category_id' => $category->id,
            ]
        );
    }
}
