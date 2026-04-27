<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $oldCategoryId = DB::table('categories')->where('slug', 'free-fire')->value('id');
        $newCategoryId = DB::table('categories')->where('slug', 'digital-tools')->value('id');

        if ($oldCategoryId && $newCategoryId && (int) $oldCategoryId !== (int) $newCategoryId) {
            DB::table('products')->where('category_id', $oldCategoryId)->update(['category_id' => $newCategoryId]);
            DB::table('categories')->where('id', $oldCategoryId)->delete();
        } elseif ($oldCategoryId) {
            DB::table('categories')
                ->where('id', $oldCategoryId)
                ->update([
                    'slug' => 'digital-tools',
                    'name' => 'Digital Tools',
                ]);
        } elseif ($newCategoryId) {
            DB::table('categories')->where('id', $newCategoryId)->update(['name' => 'Digital Tools']);
        }

        DB::table('products')
            ->where('name', 'Aurora-VN')
            ->update([
                'description' => 'Licensed desktop utility package with setup support and duration-based access.',
            ]);

        DB::table('products')
            ->where('name', 'XG-Team')
            ->update([
                'description' => 'Desktop utility license with setup guidance and access support.',
            ]);

        $this->syncProductFeatures('Aurora-VN', [
            'Duration-based license access',
            'Setup guide included',
            'Customer support available',
        ]);

        $this->syncProductFeatures('XG-Team', [
            'Desktop access utility',
            'Setup tutorial included',
            'Customer support available',
        ]);
    }

    public function down(): void
    {
        $categoryId = DB::table('categories')->where('slug', 'digital-tools')->value('id');

        if ($categoryId && ! DB::table('categories')->where('slug', 'free-fire')->exists()) {
            DB::table('categories')
                ->where('id', $categoryId)
                ->update([
                    'slug' => 'free-fire',
                    'name' => 'Free Fire',
                ]);
        }

        DB::table('products')
            ->where('name', 'Aurora-VN')
            ->update(['description' => 'Aurora ']);

        DB::table('products')
            ->where('name', 'XG-Team')
            ->update(['description' => 'XG access']);

        $this->syncProductFeatures('Aurora-VN', [
            'Holo',
            'Pull',
            'Etc',
        ]);

        $this->syncProductFeatures('XG-Team', [
            'Remove Logo PC',
            '120 FPS Unlocked On Free Fire Setting',
        ]);
    }

    private function syncProductFeatures(string $productName, array $features): void
    {
        $productId = DB::table('products')->where('name', $productName)->value('id');

        if (! $productId) {
            return;
        }

        DB::table('features')->where('product_id', $productId)->delete();

        foreach ($features as $feature) {
            DB::table('features')->insert([
                'product_id' => $productId,
                'name' => $feature,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
