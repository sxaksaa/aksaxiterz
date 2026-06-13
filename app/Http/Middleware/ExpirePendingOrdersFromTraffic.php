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
        $interval = max(10, (int) config('services.payments.traffic_cleanup_seconds', 60));
        $cacheStore = (string) config('services.payments.traffic_cleanup_cache_store', 'file');

        try {
            if (Cache::store($cacheStore)->add('orders:traffic-expiry-cleanup', true, $interval)) {
                $this->pendingOrderExpirationService->expire(limit: 500);
            }
        } catch (\Throwable $error) {
            Log::warning('TRAFFIC ORDER EXPIRY CLEANUP ERROR: '.$error->getMessage());
        }

        return $next($request);
    }
}
