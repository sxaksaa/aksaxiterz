<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        Category::updateOrCreate(
            ['slug' => 'free-fire'],
            ['name' => 'Free Fire']
        );

        Category::updateOrCreate(
            ['slug' => 'testing-payment'],
            ['name' => 'Testing Payment']
        );
    }
}
