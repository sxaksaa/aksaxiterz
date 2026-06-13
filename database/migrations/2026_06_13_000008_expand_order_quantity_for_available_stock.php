<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedInteger('quantity')->default(1)->change();
            $table->decimal('price', 20, 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedTinyInteger('quantity')->default(1)->change();
            $table->decimal('price', 12, 6)->nullable()->change();
        });
    }
};
