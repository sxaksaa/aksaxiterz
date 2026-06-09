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
            $table->string('payment_match_key', 64)->nullable()->after('payment_reference');
        });

        $seen = [];

        DB::table('orders')
            ->where('payment_method', 'crypto')
            ->whereIn('status', ['pending', 'cancelled'])
            ->where('created_at', '>', now()->subDay())
            ->whereNotNull('payment_payload')
            ->orderBy('id')
            ->select(['id', 'payment_payload'])
            ->chunkById(200, function ($orders) use (&$seen): void {
                foreach ($orders as $order) {
                    $payload = is_array($order->payment_payload)
                        ? $order->payment_payload
                        : json_decode((string) $order->payment_payload, true);
                    $network = strtolower(trim((string) ($payload['network'] ?? '')));
                    $address = strtolower(trim((string) ($payload['address'] ?? '')));
                    $contract = strtolower(trim((string) ($payload['contract'] ?? '')));
                    $amount = trim((string) ($payload['amount'] ?? ''));

                    if ($network === '' || $address === '' || ! is_numeric($amount)) {
                        continue;
                    }

                    $amount = number_format((float) $amount, 6, '.', '');
                    $matchKey = hash('sha256', implode('|', [$network, $address, $contract, $amount]));

                    if (isset($seen[$matchKey])) {
                        continue;
                    }

                    $seen[$matchKey] = true;

                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update(['payment_match_key' => $matchKey]);
                }
            });

        Schema::table('orders', function (Blueprint $table): void {
            $table->unique('payment_match_key', 'orders_payment_match_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_payment_match_key_unique');
            $table->dropColumn('payment_match_key');
        });
    }
};
