<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->unsignedTinyInteger('discount_percent');
            $table->unsignedInteger('max_discount');
            $table->unsignedInteger('minimum_purchase')->default(20000);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedSmallInteger('per_user_limit')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'expires_at'], 'vouchers_availability_index');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('voucher_id')
                ->nullable()
                ->after('package_id')
                ->constrained()
                ->nullOnDelete();

            $table->index(['voucher_id', 'user_id', 'status'], 'orders_voucher_user_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_voucher_user_status_index');
            $table->dropConstrainedForeignId('voucher_id');
        });

        Schema::dropIfExists('vouchers');
    }
};
