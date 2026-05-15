<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        if (! Schema::hasColumn('products', 'slug')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->string('slug')->nullable();
            });
        }

        $usedSlugs = [];

        DB::table('products')
            ->select(['id', 'name', 'slug'])
            ->orderBy('id')
            ->get()
            ->each(function ($product) use (&$usedSlugs): void {
                $baseSlug = Str::slug($product->slug ?: $product->name) ?: 'product-'.$product->id;
                $slug = $baseSlug;
                $suffix = 2;

                while (in_array($slug, $usedSlugs, true)) {
                    $slug = $baseSlug.'-'.$suffix;
                    $suffix++;
                }

                $usedSlugs[] = $slug;

                DB::table('products')
                    ->where('id', $product->id)
                    ->update([
                        'slug' => $slug,
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'slug')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('slug');
        });
    }
};
