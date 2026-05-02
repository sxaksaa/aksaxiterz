<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasTable('packages')) {
            return;
        }

        $productId = DB::table('products')
            ->where('name', 'Aurora-VN')
            ->value('id');

        if (! $productId) {
            return;
        }

        $package = DB::table('packages')
            ->where('product_id', $productId)
            ->whereIn('name', ['3 Hari', '3 Days'])
            ->first();

        $values = [
            'product_id' => $productId,
            'name' => '3 Days',
            'price' => 45000,
            'price_usdt' => 3,
            'updated_at' => now(),
        ];

        if ($package) {
            DB::table('packages')
                ->where('id', $package->id)
                ->update($values);

            return;
        }

        DB::table('packages')->insert($values + [
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasTable('packages')) {
            return;
        }

        $productId = DB::table('products')
            ->where('name', 'Aurora-VN')
            ->value('id');

        if (! $productId) {
            return;
        }

        DB::table('packages')
            ->where('product_id', $productId)
            ->where('name', '3 Days')
            ->where('price', 45000)
            ->where('price_usdt', 3)
            ->delete();
    }
};
