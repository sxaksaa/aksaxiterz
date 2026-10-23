<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PageTiming
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('checkout/*', 'checkout', 'cart/checkout', 'admin')) {
            return $next($request);
        }

        $started = hrtime(true);
        $connection = DB::connection();
        $queryTime = $connection->totalQueryDuration();
        $response = $next($request);

        if ($request->user() && $response->getStatusCode() < 500) {
            $response->headers->set('Server-Timing', sprintf(
                'app;dur=%.2f, db;dur=%.2f',
                (hrtime(true) - $started) / 1_000_000,
                max(0, $connection->totalQueryDuration() - $queryTime)
            ));
        }

        return $response;
    }
}
