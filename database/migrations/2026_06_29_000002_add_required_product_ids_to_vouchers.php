<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->json('required_product_ids')->nullable()->after('required_package_ids');
        });

        DB::table('vouchers')
            ->select(['id', 'required_package_ids'])
            ->whereNotNull('required_package_ids')
            ->orderBy('id')
            ->chunkById(100, function ($vouchers): void {
                foreach ($vouchers as $voucher) {
                    $packageIds = json_decode((string) $voucher->required_package_ids, true);

                    if (! is_array($packageIds) || $packageIds === []) {
                        continue;
                    }

                    $productIds = DB::table('packages')
                        ->whereIn('id', $packageIds)
                        ->pluck('product_id')
                        ->map(fn ($id) => (int) $id)
                        ->filter(fn (int $id) => $id > 0)
                        ->unique()
                        ->values()
                        ->all();

                    if ($productIds !== []) {
                        DB::table('vouchers')
                            ->where('id', $voucher->id)
                            ->update(['required_product_ids' => json_encode($productIds)]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->dropColumn('required_product_ids');
        });
    }
};
