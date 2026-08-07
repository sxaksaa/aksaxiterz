<?php

namespace App\Providers;

use App\Models\CartItem;
use App\Models\Order;
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

        View::composer(['partials.navbar', 'partials.pending-payment-reminder'], function ($view): void {
            $context = request()->attributes->get('storefront_user_context');

            if (! is_array($context)) {
                $context = [
                    'cartCount' => 0,
                    'pendingOrderCount' => 0,
                    'pendingOrder' => null,
                ];

                if (Auth::check()) {
                    $pendingOrders = Order::query()
                        ->where('user_id', Auth::id())
                        ->where('status', 'pending')
                        ->where(fn ($query) => $query
                            ->whereNull('expired_at')
                            ->orWhere('expired_at', '>', now()))
                        ->latest()
                        ->get(['id', 'order_id', 'payment_method', 'expired_at']);

                    $context = [
                        'cartCount' => (int) CartItem::where('user_id', Auth::id())->sum('quantity'),
                        'pendingOrderCount' => $pendingOrders->count(),
                        'pendingOrder' => $pendingOrders->first(),
                    ];
                }

                request()->attributes->set('storefront_user_context', $context);
            }

            $view->with(array_merge(
                $context,
                array_intersect_key($view->getData(), $context)
            ));
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
