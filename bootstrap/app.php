<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnforceCanonicalUrl;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request as HttpRequest;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = env('TRUSTED_PROXIES');

        if ($trustedProxies === null || $trustedProxies === '') {
            $trustedProxies = env('APP_ENV', 'production') === 'production' ? '*' : null;
        }

        $middleware->trustProxies(
            at: $trustedProxies,
            headers: HttpRequest::HEADER_X_FORWARDED_FOR |
                HttpRequest::HEADER_X_FORWARDED_HOST |
                HttpRequest::HEADER_X_FORWARDED_PORT |
                HttpRequest::HEADER_X_FORWARDED_PROTO |
                HttpRequest::HEADER_X_FORWARDED_PREFIX
        );

        $middleware->append([
            SecurityHeaders::class,
            EnforceCanonicalUrl::class,
        ]);
        $middleware->alias([
            'admin' => EnsureAdmin::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'pakasir-callback',
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('orders:scan-crypto --limit=50')
            ->everyMinute()
            ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
