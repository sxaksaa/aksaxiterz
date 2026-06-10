<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

class CheckoutLockService
{
    public function run(int $userId, Closure $callback): mixed
    {
        $ttlSeconds = max(10, (int) config('services.payments.checkout_lock_ttl_seconds', 120));
        $waitSeconds = max(0, (int) config('services.payments.checkout_lock_wait_seconds', 5));

        return Cache::lock("payment-checkout:user:{$userId}", $ttlSeconds)
            ->block($waitSeconds, $callback);
    }
}
