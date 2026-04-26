<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $category = Category::where('slug', 'free-fire')->firstOrFail();
        $testingCategory = Category::where('slug', 'testing-payment')->firstOrFail();

        Product::updateOrCreate(
            ['name' => 'Aurora-VN'],
            [
                'description' => 'Aurora ',
                'category_id' => $category->id,
            ]
        );

        Product::updateOrCreate(
            ['name' => 'XG-Team'],
            [
                'description' => 'XG access',
                'category_id' => $category->id,
            ]
        );

        Product::updateOrCreate(
            ['name' => 'Testing Payment'],
            [
                'description' => 'Low-cost product for testing the real payment flow.',
                'category_id' => $testingCategory->id,
            ]
        );
    }
}
