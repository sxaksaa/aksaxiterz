<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unique('order_id', 'orders_order_id_unique');
            $table->index(['user_id', 'status', 'expired_at'], 'orders_user_status_expired_index');
        });

        Schema::table('licenses', function (Blueprint $table) {
            $table->unique('order_id', 'licenses_order_id_unique');
        });

        Schema::table('license_stocks', function (Blueprint $table) {
            $table->unique('license_key', 'license_stocks_license_key_unique');
            $table->index(['product_id', 'package_id', 'is_sold'], 'license_stocks_available_index');
        });
    }

    public function down(): void
    {
        Schema::table('license_stocks', function (Blueprint $table) {
            $table->dropIndex('license_stocks_available_index');
            $table->dropUnique('license_stocks_license_key_unique');
        });

        Schema::table('licenses', function (Blueprint $table) {
            $table->dropUnique('licenses_order_id_unique');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_user_status_expired_index');
            $table->dropUnique('orders_order_id_unique');
        });
    }
};
