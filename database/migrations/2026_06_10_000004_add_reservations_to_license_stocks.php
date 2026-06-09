<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_stocks', function (Blueprint $table): void {
            $table->unsignedBigInteger('reserved_order_id')->nullable()->after('is_sold');
            $table->timestamp('reserved_until')->nullable()->after('reserved_order_id');
            $table->unique('reserved_order_id', 'license_stocks_reserved_order_unique');
            $table->index(
                ['product_id', 'package_id', 'is_sold', 'reserved_until'],
                'license_stocks_reservation_available_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('license_stocks', function (Blueprint $table): void {
            $table->dropIndex('license_stocks_reservation_available_index');
            $table->dropUnique('license_stocks_reserved_order_unique');
            $table->dropColumn(['reserved_order_id', 'reserved_until']);
        });
    }
};
