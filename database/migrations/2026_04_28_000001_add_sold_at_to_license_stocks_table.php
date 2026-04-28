<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_stocks', function (Blueprint $table) {
            $table->timestamp('sold_at')->nullable();
        });

        DB::table('license_stocks')
            ->where('is_sold', true)
            ->whereNull('sold_at')
            ->update(['sold_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('license_stocks', function (Blueprint $table) {
            $table->dropColumn('sold_at');
        });
    }
};
