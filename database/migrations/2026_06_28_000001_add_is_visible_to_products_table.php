<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || Schema::hasColumn('products', 'is_visible')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('is_visible')->default(true)->after('status')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'is_visible')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('is_visible');
        });
    }
};
