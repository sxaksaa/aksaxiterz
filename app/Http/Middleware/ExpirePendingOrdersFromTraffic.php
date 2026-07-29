<?php

namespace App\Http\Middleware;

use App\Services\PendingOrderExpirationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ExpirePendingOrdersFromTraffic
{
    public function __construct(
        private readonly PendingOrderExpirationService $pendingOrderExpirationService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('up')) {
            return $next($request);
        }

        // The scheduler is the primary cleanup path. This request-based pass is
        // only a low-frequency fallback for hosts where cron is temporarily down.
        $interval = max(300, (int) config('services.payments.traffic_cleanup_seconds', 300));
        $limit = max(1, (int) config('services.payments.traffic_cleanup_limit', 20));
        $cacheStore = (string) config('services.payments.traffic_cleanup_cache_store', 'file');

        try {
            if (Cache::store($cacheStore)->add('orders:traffic-expiry-cleanup', true, $interval)) {
                $this->pendingOrderExpirationService->expire(limit: $limit);
            }
        } catch (\Throwable $error) {
            Log::warning('TRAFFIC ORDER EXPIRY CLEANUP ERROR: '.$error->getMessage());
        }

        return $next($request);
    }
}
