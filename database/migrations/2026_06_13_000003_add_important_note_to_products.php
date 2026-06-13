<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->text('important_note')->nullable()->after('description');
        });

        if (! Schema::hasTable('features')) {
            return;
        }

        DB::table('features')
            ->orderBy('id')
            ->get()
            ->groupBy('product_id')
            ->each(function ($features, $productId): void {
                $importantNote = $features
                    ->pluck('name')
                    ->map(fn ($note) => trim((string) $note))
                    ->filter()
                    ->map(fn ($note) => rtrim($note, ".!?\t\n\r\0\x0B").'.')
                    ->implode(' ');

                if ($importantNote !== '') {
                    DB::table('products')
                        ->where('id', $productId)
                        ->update(['important_note' => $importantNote]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('important_note');
        });
    }
};
