<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        Category::where('slug', 'digital-tools')
            ->whereDoesntHave('products')
            ->delete();

        foreach ([
            'pc' => 'PC',
            'mobile' => 'Mobile',
            'android' => 'Android',
            'ios' => 'iOS',
        ] as $slug => $name) {
            Category::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name]
            );
        }
    }
}
