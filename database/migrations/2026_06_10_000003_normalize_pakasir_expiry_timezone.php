<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $timezone = (string) config('app.timezone', 'Asia/Jakarta');

        DB::table('orders')
            ->where('payment_method', 'pakasir')
            ->whereNotNull('payment_payload')
            ->orderBy('id')
            ->select(['id', 'payment_payload'])
            ->chunkById(200, function ($orders) use ($timezone): void {
                foreach ($orders as $order) {
                    $payload = is_array($order->payment_payload)
                        ? $order->payment_payload
                        : json_decode((string) $order->payment_payload, true);
                    $expiredAt = is_array($payload) ? ($payload['expired_at'] ?? null) : null;

                    if (! is_string($expiredAt) || $expiredAt === '') {
                        continue;
                    }

                    $normalized = preg_replace('/\.(\d{6})\d+(Z|[+-]\d{2}:\d{2})$/', '.$1$2', $expiredAt);

                    try {
                        $localExpiry = Carbon::parse($normalized)
                            ->timezone($timezone)
                            ->format('Y-m-d H:i:s');
                    } catch (\Throwable) {
                        continue;
                    }

                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update(['expired_at' => $localExpiry]);
                }
            });
    }

    public function down(): void
    {
    }
};
