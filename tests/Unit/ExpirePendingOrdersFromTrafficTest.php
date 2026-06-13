<?php

namespace Tests\Unit;

use App\Http\Middleware\ExpirePendingOrdersFromTraffic;
use App\Services\PendingOrderExpirationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ExpirePendingOrdersFromTrafficTest extends TestCase
{
    public function test_traffic_cleanup_runs_at_most_once_per_interval(): void
    {
        config([
            'services.payments.traffic_cleanup_seconds' => 60,
            'services.payments.traffic_cleanup_limit' => 20,
            'services.payments.traffic_cleanup_cache_store' => 'array',
        ]);
        Cache::store('array')->flush();

        $service = $this->mock(PendingOrderExpirationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('expire')
                ->once()
                ->with(null, 20)
                ->andReturn(['cancelled' => 0, 'pakasir' => 0, 'crypto' => 0]);
        });
        $middleware = new ExpirePendingOrdersFromTraffic($service);
        $next = fn () => new Response('ok');

        $this->assertSame('ok', $middleware->handle(Request::create('/products'), $next)->getContent());
        $this->assertSame('ok', $middleware->handle(Request::create('/guides'), $next)->getContent());
    }

    public function test_traffic_cleanup_skips_health_check(): void
    {
        $service = $this->mock(PendingOrderExpirationService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('expire');
        });
        $middleware = new ExpirePendingOrdersFromTraffic($service);

        $response = $middleware->handle(Request::create('/up'), fn () => new Response('healthy'));

        $this->assertSame('healthy', $response->getContent());
    }
}
