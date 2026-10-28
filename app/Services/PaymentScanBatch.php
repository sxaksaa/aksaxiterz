<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PaymentScanBatch
{
    public function next(Builder $eligible, string $method, int $limit): Collection
    {
        $limit = max(1, $limit);
        $cursorKey = 'payment-scan:cursor:'.$method;

        // Advance before provider I/O, including failures and legacy payloads.
        return Cache::lock($cursorKey.':lock', 10)->block(1, function () use ($eligible, $limit, $cursorKey): Collection {
            $cursor = max(0, (int) Cache::get($cursorKey, 0));
            $orders = (clone $eligible)->reorder()->where('id', '>', $cursor)
                ->orderBy('id')->limit($limit)->get();

            if ($cursor > 0 && $orders->count() < $limit) {
                $wrapped = (clone $eligible)->reorder()->where('id', '<=', $cursor)
                    ->orderBy('id')->limit($limit - $orders->count())->get();
                $orders = $orders->concat($wrapped);
            }

            if ($orders->isNotEmpty()) {
                Cache::forever($cursorKey, (int) $orders->last()->id);
            }

            return $orders;
        });
    }
}
