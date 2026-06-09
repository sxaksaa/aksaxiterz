<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('payment_reference', 191)->nullable()->after('payment_payload');
        });

        $seen = [];

        DB::table('orders')
            ->where('payment_method', 'crypto')
            ->whereNotNull('payment_payload')
            ->orderBy('id')
            ->select(['id', 'payment_payload'])
            ->chunkById(200, function ($orders) use (&$seen): void {
                foreach ($orders as $order) {
                    $payload = is_array($order->payment_payload)
                        ? $order->payment_payload
                        : json_decode((string) $order->payment_payload, true);
                    $reference = strtolower(trim((string) ($payload['tx_hash'] ?? '')));

                    if ($reference === '' || strlen($reference) > 191 || isset($seen[$reference])) {
                        continue;
                    }

                    $seen[$reference] = true;

                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update(['payment_reference' => $reference]);
                }
            });

        Schema::table('orders', function (Blueprint $table): void {
            $table->unique('payment_reference', 'orders_payment_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_payment_reference_unique');
            $table->dropColumn('payment_reference');
        });
    }
};
