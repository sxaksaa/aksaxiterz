<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->decimal('max_discount_usdt', 12, 6)->default(0.25)->after('max_discount');
            $table->decimal('max_discount_usdc', 12, 6)->default(0.25)->after('max_discount_usdt');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->dropColumn(['max_discount_usdt', 'max_discount_usdc']);
        });
    }
};
