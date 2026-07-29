<?php

namespace App\Providers;

use App\Models\CartItem;
use Illuminate\Support\Facades\Auth;
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
    }
}
