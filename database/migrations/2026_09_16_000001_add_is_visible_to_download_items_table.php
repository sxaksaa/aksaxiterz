<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('download_items') || Schema::hasColumn('download_items', 'is_visible')) {
            return;
        }

        Schema::table('download_items', function (Blueprint $table): void {
            $table->boolean('is_visible')->default(true)->after('links')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('download_items') || ! Schema::hasColumn('download_items', 'is_visible')) {
            return;
        }

        Schema::table('download_items', function (Blueprint $table): void {
            $table->dropColumn('is_visible');
        });
    }
};
