<?php

use App\Http\Middleware\EnforceCanonicalUrl;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\ExpirePendingOrdersFromTraffic;
use App\Http\Middleware\LogAdminActivity;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request as HttpRequest;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = trim((string) env('TRUSTED_PROXIES', ''));

        if ($trustedProxies !== '') {
            $middleware->trustProxies(
                at: $trustedProxies,
                headers: HttpRequest::HEADER_X_FORWARDED_FOR |
                    HttpRequest::HEADER_X_FORWARDED_HOST |
                    HttpRequest::HEADER_X_FORWARDED_PORT |
                    HttpRequest::HEADER_X_FORWARDED_PROTO |
                    HttpRequest::HEADER_X_FORWARDED_PREFIX
            );
        }

        $middleware->append([
            ExpirePendingOrdersFromTraffic::class,
            SecurityHeaders::class,
            EnforceCanonicalUrl::class,
        ]);
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'admin.activity' => LogAdminActivity::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
