<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;

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
