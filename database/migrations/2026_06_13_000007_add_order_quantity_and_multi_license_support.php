<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedTinyInteger('quantity')->default(1)->after('package_id');
        });

        Schema::table('licenses', function (Blueprint $table): void {
            $table->dropUnique('licenses_order_id_unique');
            $table->index('order_id', 'licenses_order_id_index');
        });

        Schema::table('license_stocks', function (Blueprint $table): void {
            $table->dropUnique('license_stocks_reserved_order_unique');
            $table->index('reserved_order_id', 'license_stocks_reserved_order_index');
        });
    }

    public function down(): void
    {
        Schema::table('license_stocks', function (Blueprint $table): void {
            $table->dropIndex('license_stocks_reserved_order_index');
            $table->unique('reserved_order_id', 'license_stocks_reserved_order_unique');
        });

        Schema::table('licenses', function (Blueprint $table): void {
            $table->dropIndex('licenses_order_id_index');
            $table->unique('order_id', 'licenses_order_id_unique');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('quantity');
        });
    }
};
