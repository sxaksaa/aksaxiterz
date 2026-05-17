<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('download_items')) {
            return;
        }

        $columns = collect(['platform', 'updated_label', 'sort_order', 'is_active'])
            ->filter(fn ($column) => Schema::hasColumn('download_items', $column))
            ->values()
            ->all();

        if ($columns) {
            Schema::table('download_items', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('download_items')) {
            return;
        }

        Schema::table('download_items', function (Blueprint $table) {
            if (! Schema::hasColumn('download_items', 'platform')) {
                $table->string('platform')->nullable();
            }

            if (! Schema::hasColumn('download_items', 'updated_label')) {
                $table->string('updated_label')->nullable();
            }

            if (! Schema::hasColumn('download_items', 'sort_order')) {
                $table->unsignedSmallInteger('sort_order')->default(0);
            }

            if (! Schema::hasColumn('download_items', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
        });
    }
};
