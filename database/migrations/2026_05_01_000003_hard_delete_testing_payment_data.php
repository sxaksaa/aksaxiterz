<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $categoryIds = Schema::hasTable('categories')
                ? DB::table('categories')
                    ->where('slug', 'testing-payment')
                    ->orWhere('name', 'Testing Payment')
                    ->pluck('id')
                    ->all()
                : [];

            $productQuery = DB::table('products')
                ->where('name', 'Testing Payment');

            if ($categoryIds !== []) {
                $productQuery->orWhereIn('category_id', $categoryIds);
            }

            $productIds = $productQuery->pluck('id')->all();

            if ($productIds === []) {
                if ($categoryIds !== []) {
                    DB::table('categories')->whereIn('id', $categoryIds)->delete();
                }

                return;
            }

            $packageIds = Schema::hasTable('packages')
                ? DB::table('packages')->whereIn('product_id', $productIds)->pluck('id')->all()
                : [];

            $orderQuery = DB::table('orders')->whereIn('product_id', $productIds);

            if ($packageIds !== []) {
                $orderQuery->orWhereIn('package_id', $packageIds);
            }

            $orderIds = $orderQuery->pluck('id')->all();
            $publicOrderIds = $orderIds !== []
                ? DB::table('orders')->whereIn('id', $orderIds)->whereNotNull('order_id')->pluck('order_id')->all()
                : [];

            if (Schema::hasTable('licenses')) {
                DB::table('licenses')
                    ->whereIn('product_id', $productIds)
                    ->when($publicOrderIds !== [], fn ($query) => $query->orWhereIn('order_id', $publicOrderIds))
                    ->delete();
            }

            if (Schema::hasTable('license_stocks')) {
                DB::table('license_stocks')
                    ->whereIn('product_id', $productIds)
                    ->when($packageIds !== [], fn ($query) => $query->orWhereIn('package_id', $packageIds))
                    ->delete();
            }

            if ($orderIds !== []) {
                DB::table('orders')->whereIn('id', $orderIds)->delete();
            }

            if (Schema::hasTable('features')) {
                DB::table('features')->whereIn('product_id', $productIds)->delete();
            }

            if ($packageIds !== []) {
                DB::table('packages')->whereIn('id', $packageIds)->delete();
            }

            DB::table('products')->whereIn('id', $productIds)->delete();

            if ($categoryIds !== []) {
                DB::table('categories')->whereIn('id', $categoryIds)->delete();
            }
        });
    }

    public function down(): void
    {
        // Intentionally irreversible: this migration permanently removes legacy test payment data.
    }
};
