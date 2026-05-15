<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $products = [
        'Aurora-VN' => [
            'category' => 'pc',
            'description' => 'Licensed desktop utility package with setup support and duration-based access.',
            'features' => [
                'Duration-based license access',
                'Setup guide included',
                'Customer support available',
            ],
            'packages' => [
                ['1 Hari', '1 Day', 20000, 1.25],
                ['3 Hari', '3 Days', 45000, 3],
                ['7 Hari', '7 Days', 100000, 6],
                ['30 Hari', '30 Days', 250000, 15],
            ],
        ],
        'XG-Team' => [
            'category' => 'pc',
            'description' => 'Desktop utility license with setup guidance and access support.',
            'features' => [
                'Desktop access utility',
                'Setup tutorial included',
                'Customer support available',
            ],
            'packages' => [
                ['7 Hari', '7 Days', 80000, 5],
            ],
        ],
        'Drip Client Root' => [
            'category' => 'android',
            'description' => 'Android root client license with setup support and duration-based access.',
            'features' => [
                'Android root client access',
                'Duration-based license delivery',
                'Setup support available',
            ],
            'packages' => [
                ['1 Hari', '1 Day', 30000, 1.75],
                ['7 Hari', '7 Days', 100000, 6],
                ['1 Month', '30 Days', 250000, 15],
            ],
            'remove_packages' => ['3 Days', '15 Days'],
        ],
        'Drip Client Non Root' => [
            'category' => 'android',
            'description' => 'Android non-root client license with setup support and duration-based access.',
            'features' => [
                'Android non-root client access',
                'Duration-based license delivery',
                'Setup support available',
            ],
            'packages' => [
                ['1 Hari', '1 Day', 25000, 1.5],
                ['3 Hari', '3 Days', 45000, 2.75],
                ['7 Hari', '7 Days', 80000, 4.75],
                ['15 Hari', '15 Days', 150000, 10],
                ['1 Month', '30 Days', 225000, 13.5],
            ],
        ],
        'Fluorite FF' => [
            'category' => 'ios',
            'description' => 'iOS Fluorite license for FF with setup guidance and duration-based access.',
            'features' => [
                'iOS Fluorite FF access',
                'Duration-based license delivery',
                'Setup support available',
            ],
            'packages' => [
                ['1 Hari', '1 Day', 50000, 3],
                ['7 Hari', '7 Days', 150000, 10],
                ['1 Month', '30 Days', 350000, 22],
            ],
        ],
        'Fluorite ML' => [
            'category' => 'ios',
            'description' => 'iOS Fluorite license for ML with setup guidance and duration-based access.',
            'features' => [
                'iOS Fluorite ML access',
                'Duration-based license delivery',
                'Setup support available',
            ],
            'packages' => [
                ['1 Hari', '1 Day', 50000, 3],
                ['7 Hari', '7 Days', 150000, 10],
                ['1 Month', '30 Days', 350000, 22],
            ],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('categories') || ! Schema::hasTable('products') || ! Schema::hasTable('packages')) {
            return;
        }

        foreach ([
            'pc' => 'PC',
            'mobile' => 'Mobile',
            'android' => 'Android',
            'ios' => 'iOS',
        ] as $slug => $name) {
            $this->upsertCategory($slug, $name);
        }

        $this->deleteEmptyCategory('digital-tools');

        foreach ($this->products as $name => $product) {
            $categoryId = DB::table('categories')->where('slug', $product['category'])->value('id');

            if (! $categoryId) {
                continue;
            }

            $this->upsertProduct($name, $categoryId, $product['description']);

            $productId = DB::table('products')->where('name', $name)->value('id');

            if (! $productId) {
                continue;
            }

            foreach ($product['packages'] as [$oldName, $packageName, $price, $priceUsdt]) {
                $package = DB::table('packages')
                    ->where('product_id', $productId)
                    ->whereIn('name', [$oldName, $packageName])
                    ->first();

                $values = [
                    'product_id' => $productId,
                    'name' => $packageName,
                    'price' => $price,
                    'price_usdt' => $priceUsdt,
                    'updated_at' => now(),
                ];

                if ($package) {
                    DB::table('packages')->where('id', $package->id)->update($values);
                } else {
                    DB::table('packages')->insert($values + ['created_at' => now()]);
                }
            }

            foreach ($product['remove_packages'] ?? [] as $packageName) {
                $this->deleteUnusedPackage($productId, $packageName);
            }

            if (Schema::hasTable('features')) {
                DB::table('features')->where('product_id', $productId)->delete();

                foreach ($product['features'] as $feature) {
                    DB::table('features')->insert([
                        'product_id' => $productId,
                        'name' => $feature,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        $newProductNames = [
            'Drip Client Root',
            'Drip Client Non Root',
            'Fluorite FF',
            'Fluorite ML',
        ];

        $productIds = DB::table('products')
            ->whereIn('name', $newProductNames)
            ->pluck('id');

        if ($productIds->isEmpty()) {
            return;
        }

        if (Schema::hasTable('features')) {
            DB::table('features')->whereIn('product_id', $productIds)->delete();
        }

        if (Schema::hasTable('packages')) {
            DB::table('packages')->whereIn('product_id', $productIds)->delete();
        }

        if (Schema::hasTable('license_stocks')) {
            DB::table('license_stocks')->whereIn('product_id', $productIds)->where('is_sold', false)->delete();
        }

        DB::table('products')->whereIn('id', $productIds)->delete();
    }

    private function upsertCategory(string $slug, string $name): void
    {
        $category = DB::table('categories')->where('slug', $slug)->first();

        if ($category) {
            DB::table('categories')
                ->where('id', $category->id)
                ->update([
                    'name' => $name,
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('categories')->insert([
            'slug' => $slug,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function upsertProduct(string $name, int $categoryId, string $description): void
    {
        $product = DB::table('products')->where('name', $name)->first();

        if ($product) {
            DB::table('products')
                ->where('id', $product->id)
                ->update([
                    'category_id' => $categoryId,
                    'description' => $description,
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('products')->insert([
            'name' => $name,
            'category_id' => $categoryId,
            'description' => $description,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function deleteEmptyCategory(string $slug): void
    {
        $categoryId = DB::table('categories')->where('slug', $slug)->value('id');

        if (! $categoryId) {
            return;
        }

        $hasProducts = DB::table('products')->where('category_id', $categoryId)->exists();

        if (! $hasProducts) {
            DB::table('categories')->where('id', $categoryId)->delete();
        }
    }

    private function deleteUnusedPackage(int $productId, string $packageName): void
    {
        $package = DB::table('packages')
            ->where('product_id', $productId)
            ->where('name', $packageName)
            ->first();

        if (! $package) {
            return;
        }

        if (Schema::hasTable('orders') && DB::table('orders')->where('package_id', $package->id)->exists()) {
            return;
        }

        if (Schema::hasTable('license_stocks')) {
            DB::table('license_stocks')
                ->where('package_id', $package->id)
                ->where('is_sold', false)
                ->delete();

            if (DB::table('license_stocks')->where('package_id', $package->id)->where('is_sold', true)->exists()) {
                return;
            }
        }

        DB::table('packages')->where('id', $package->id)->delete();
    }
};
