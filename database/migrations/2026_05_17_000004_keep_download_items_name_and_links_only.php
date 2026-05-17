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

        $columns = collect(['version', 'description'])
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
            if (! Schema::hasColumn('download_items', 'version')) {
                $table->string('version')->nullable();
            }

            if (! Schema::hasColumn('download_items', 'description')) {
                $table->text('description')->nullable();
            }
        });
    }
};
