<?php

namespace App\Support;

use App\Models\Order;

class OrderStats
{
    public static function forUser(int $userId): array
    {
        $stats = Order::query()
            ->where('user_id', $userId)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending")
            ->first();

        return [
            'total' => (int) ($stats?->total ?? 0),
            'paid' => (int) ($stats?->paid ?? 0),
            'pending' => (int) ($stats?->pending ?? 0),
        ];
    }
}
