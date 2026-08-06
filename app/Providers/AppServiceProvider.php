<?php

namespace App\Providers;

use App\Models\CartItem;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ((bool) config('security.force_https')) {
            URL::forceScheme('https');
        }

        View::composer('partials.navbar', function ($view): void {
            $cartCount = Auth::check()
                ? (int) CartItem::where('user_id', Auth::id())->sum('quantity')
                : 0;

            $view->with('cartCount', $cartCount);
        });

        Event::listen(DiagnosingHealth::class, function (): void {
            DB::select('select 1');

            $cacheKey = 'health-check:'.bin2hex(random_bytes(8));
            Cache::put($cacheKey, 'ok', 10);

            if (Cache::get($cacheKey) !== 'ok') {
                throw new \RuntimeException('Cache health check failed.');
            }

            Cache::forget($cacheKey);

            if (! File::isWritable(storage_path('framework'))) {
                throw new \RuntimeException('Laravel storage is not writable.');
            }
        });
    }
}
