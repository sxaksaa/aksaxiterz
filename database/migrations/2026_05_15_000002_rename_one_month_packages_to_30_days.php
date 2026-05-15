<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('packages') || ! Schema::hasTable('products')) {
            return;
        }

        $productNames = [
            'Drip Client Root',
            'Drip Client Non Root',
            'Fluorite FF',
            'Fluorite ML',
        ];

        $productIds = DB::table('products')
            ->whereIn('name', $productNames)
            ->pluck('id');

        if ($productIds->isEmpty()) {
            return;
        }

        DB::table('packages')
            ->whereIn('product_id', $productIds)
            ->where('name', '1 Month')
            ->update([
                'name' => '30 Days',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('packages') || ! Schema::hasTable('products')) {
            return;
        }

        $productNames = [
            'Drip Client Root',
            'Drip Client Non Root',
            'Fluorite FF',
            'Fluorite ML',
        ];

        $productIds = DB::table('products')
            ->whereIn('name', $productNames)
            ->pluck('id');

        if ($productIds->isEmpty()) {
            return;
        }

        DB::table('packages')
            ->whereIn('product_id', $productIds)
            ->where('name', '30 Days')
            ->update([
                'name' => '1 Month',
                'updated_at' => now(),
            ]);
    }
};
