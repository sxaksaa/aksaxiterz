<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')
            ->where('payment_method', 'crypto')
            ->whereNotNull('payment_payload')
            ->orderBy('id')
            ->select(['id', 'payment_payload'])
            ->chunkById(200, function ($orders): void {
                foreach ($orders as $order) {
                    $payload = is_array($order->payment_payload)
                        ? $order->payment_payload
                        : json_decode((string) $order->payment_payload, true);

                    if (! is_array($payload)) {
                        continue;
                    }

                    $changed = isset($payload['amount_mismatch']) ||
                        isset($payload['amount_mismatches']) ||
                        ($payload['scanner_status'] ?? null) === 'amount_mismatch';

                    if (! $changed) {
                        continue;
                    }

                    unset($payload['amount_mismatch'], $payload['amount_mismatches']);

                    if (($payload['scanner_status'] ?? null) === 'amount_mismatch') {
                        $payload['scanner_status'] = 'pending';
                    }

                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update(['payment_payload' => json_encode($payload)]);
                }
            });
    }

    public function down(): void {}
};
