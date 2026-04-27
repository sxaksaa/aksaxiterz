<?php

use App\Http\Controllers\PaymentController;
use App\Models\Category;
use App\Models\License;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

/*
|--------------------------------------------------------------------------
| HOME & API PRODUCTS
|--------------------------------------------------------------------------
*/

Route::get('/', function (Request $request) {
    $showTestProducts = (bool) config('links.show_test_products');

    $categories = Category::query()
        ->when(! $showTestProducts, fn ($query) => $query->where('slug', '!=', 'testing-payment'))
        ->get();

    $query = Product::with([
        'category',
        'packages' => fn ($query) => $query->withCount('availableLicenseStocks')->orderBy('price'),
    ])->withCount('availableLicenseStocks');

    if (! $showTestProducts) {
        $query->whereHas('category', fn ($categoryQuery) => $categoryQuery->where('slug', '!=', 'testing-payment'));
    }

    if ($request->category) {
        $category = Category::where('slug', $request->category)
            ->when(! $showTestProducts, fn ($categoryQuery) => $categoryQuery->where('slug', '!=', 'testing-payment'))
            ->first();

        if ($category) {
            $query->where('category_id', $category->id);
        }
    }

    if ($request->search) {
        $query->where('name', 'like', '%'.$request->search.'%');
    }

    $products = $query->get();

    return view('home', compact('categories', 'products'));
});

$productsFragment = function (Request $request) {
    $showTestProducts = (bool) config('links.show_test_products');

    $query = Product::with([
        'category',
        'packages' => fn ($query) => $query->withCount('availableLicenseStocks')->orderBy('price'),
    ])->withCount('availableLicenseStocks');

    if (! $showTestProducts) {
        $query->whereHas('category', fn ($categoryQuery) => $categoryQuery->where('slug', '!=', 'testing-payment'));
    }

    if ($request->search) {
        $query->where('name', 'like', '%'.$request->search.'%');
    }

    if ($request->category) {
        $category = Category::where('slug', $request->category)
            ->when(! $showTestProducts, fn ($categoryQuery) => $categoryQuery->where('slug', '!=', 'testing-payment'))
            ->first();

        if ($category) {
            $query->where('category_id', $category->id);
        }
    }

    $products = $query->get();

    return view('partials.product-card', compact('products'));
};

Route::get('/products-fragment', $productsFragment)->name('products.fragment');
Route::get('/api/products', $productsFragment);

Route::get('/downloads', function () {
    $downloads = collect(config('links.downloads', []))
        ->filter(fn ($download) => filled($download['name'] ?? null))
        ->values();
    $discordUrl = config('links.discord_url');

    return view('downloads', compact('downloads', 'discordUrl'));
});

$legalPage = function (string $slug) {
    $page = config("legal.pages.{$slug}");

    abort_if(! $page, 404);

    return view('legal', array_merge($page, [
        'slug' => $slug,
        'updatedAt' => config('legal.updated_at'),
    ]));
};

Route::get('/terms', fn () => $legalPage('terms'))->name('terms');
Route::get('/privacy', fn () => $legalPage('privacy'))->name('privacy');
Route::get('/refund-policy', fn () => $legalPage('refund-policy'))->name('refund-policy');
Route::get('/contact', fn () => $legalPage('contact'))->name('contact');

/*
|--------------------------------------------------------------------------
| PRODUCT DETAIL
|--------------------------------------------------------------------------
*/
Route::get('/product/{id}', function ($id) {
    $product = Product::with([
        'category',
        'features',
        'packages' => fn ($query) => $query->withCount('availableLicenseStocks')->orderBy('price'),
    ])
        ->withCount('availableLicenseStocks')
        ->findOrFail($id);

    if (! config('links.show_test_products') && $product->category?->slug === 'testing-payment') {
        abort(404);
    }

    return view('product-detail', compact('product'));
});

/*
|--------------------------------------------------------------------------
| PAYMENT (WAJIB LOGIN)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {

    // Pay again
    Route::post('/pay-again/{id}', [PaymentController::class, 'payAgain']);
    Route::post('/cancel-order/{id}', [PaymentController::class, 'cancelOrder']);

    // Midtrans pay page
    Route::get('/midtrans-pay', function () {
        $token = request('token');
        $orderId = request('order_id');

        abort_if(blank($token), 404);

        return view('midtrans-pay', compact('token', 'orderId'));
    })->name('midtrans.pay.page');

    // Midtrans
    Route::post('/process-order/{id}', [PaymentController::class, 'payMidtrans'])
        ->middleware('throttle:20,1');
    Route::post('/sync-midtrans-order/{orderId}', [PaymentController::class, 'syncMidtransOrder'])
        ->middleware('throttle:10,1');
    Route::match(['get', 'post'], '/sync-crypto-order/{orderId}', [PaymentController::class, 'syncCryptoOrder'])
        ->middleware('throttle:20,1');

    // Crypto
    Route::post('/pay-crypto/{id}', [PaymentController::class, 'payCrypto'])
        ->middleware('throttle:20,1');

    // Check latest order for polling.
    Route::get('/check-order', function () {

        $order = Order::where('user_id', auth()->id())->latest()->first();

        if (! $order) {
            return response()->json([
                'status' => null,
                'remaining' => 0,
            ]);
        }

        if (! $order->expired_at) {
            return response()->json([
                'status' => $order->status,
                'remaining' => 0,
                'order_id' => $order->order_id,
                'payment_method' => $order->payment_method,
                'can_sync_crypto' => $order->payment_method === 'crypto' &&
                    (bool) $order->payment_url &&
                    $order->status === 'pending' &&
                    $order->created_at &&
                    $order->created_at->gt(now()->subDay()),
            ]);
        }

        $remaining = Carbon::now()->diffInSeconds($order->expired_at, false);

        $canStillVerifyCrypto = $order->payment_method === 'crypto' &&
            $order->created_at &&
            $order->created_at->gt(now()->subDay());

        if ($remaining <= 0 && $order->status === 'pending' && ! $canStillVerifyCrypto) {
            $order->update(['status' => 'cancelled']);
        }

        return response()->json([
            'status' => $order->status,
            'remaining' => max(0, (int) $remaining),
            'order_id' => $order->order_id,
            'payment_method' => $order->payment_method,
            'can_sync_crypto' => $order->payment_method === 'crypto' &&
                (bool) $order->payment_url &&
                $order->status === 'pending' &&
                $order->created_at &&
                $order->created_at->gt(now()->subDay()),
        ]);
    });

    // License
    Route::get('/licenses', function () {
        $licenses = License::where('user_id', auth()->id())->latest()->get();

        return view('licenses', compact('licenses'));
    });

    // Orders
    Route::get('/orders', function () {

        Order::where('status', 'pending')
            ->whereNotNull('expired_at')
            ->where('expired_at', '<', now())
            ->where(function ($query) {
                $query->where('payment_method', '!=', 'crypto')
                    ->orWhere('created_at', '<', now()->subDay());
            })
            ->update(['status' => 'cancelled']);

        $orderStats = [
            'total' => Order::where('user_id', auth()->id())->count(),
            'paid' => Order::where('user_id', auth()->id())->where('status', 'paid')->count(),
            'pending' => Order::where('user_id', auth()->id())->where('status', 'pending')->count(),
        ];

        $orders = Order::with(['product', 'package'])
            ->where('user_id', auth()->id())
            ->latest()
            ->paginate(8)
            ->withPath('/orders');

        return view('orders', compact('orders', 'orderStats'));
    });

    Route::get('/orders-fragment', function () {
        Order::where('status', 'pending')
            ->whereNotNull('expired_at')
            ->where('expired_at', '<', now())
            ->where(function ($query) {
                $query->where('payment_method', '!=', 'crypto')
                    ->orWhere('created_at', '<', now()->subDay());
            })
            ->update(['status' => 'cancelled']);

        $orders = Order::with(['product', 'package'])
            ->where('user_id', auth()->id())
            ->latest()
            ->paginate(8)
            ->withPath('/orders');

        return view('partials.orders-list', compact('orders'));
    });

    Route::get('/orders-data', function () {
        return Order::with(['product', 'package'])
            ->where('user_id', auth()->id())
            ->latest()
            ->take(10)
            ->get();
    });
});

/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/

$isSafeLoginRedirect = function (Request $request, string $redirect): bool {
    if (str_starts_with($redirect, '/')) {
        return ! str_starts_with($redirect, '//');
    }

    $redirectHost = parse_url($redirect, PHP_URL_HOST);

    return $redirectHost && hash_equals($request->getHost(), $redirectHost);
};

Route::get('/auth/google', function (Request $request) use ($isSafeLoginRedirect) {
    $redirect = $request->query('redirect');

    if (is_string($redirect) && $isSafeLoginRedirect($request, $redirect)) {
        session(['login_redirect' => $redirect]);
        Cookie::queue(cookie(
            'login_redirect',
            $redirect,
            10,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax'
        ));
    }

    return Socialite::driver('google')->redirect();
});

Route::get('/auth/google/callback', function (Request $request) use ($isSafeLoginRedirect) {

    try {
        $googleUser = Socialite::driver('google')->user();
    } catch (InvalidStateException $e) {
        Log::warning('GOOGLE LOGIN STATE MISMATCH, retrying stateless auth', [
            'host' => $request->getHost(),
            'has_session_cookie' => $request->hasCookie(config('session.cookie')),
        ]);

        $googleUser = Socialite::driver('google')->stateless()->user();
    }

    $user = User::updateOrCreate(
        ['email' => $googleUser->email],
        [
            'name' => $googleUser->name,
            'avatar' => $googleUser->getAvatar(),
            'password' => bcrypt(Str::random(16)),
        ]
    );

    Auth::login($user, true);

    $redirect = session()->pull('login_redirect')
        ?? $request->cookie('login_redirect')
        ?? '/';

    Cookie::queue(Cookie::forget('login_redirect'));

    return redirect($isSafeLoginRedirect($request, $redirect) ? $redirect : '/');
});

Route::post('/logout', function () {
    Auth::logout();

    return redirect('/');
});

Route::get('/login', function () {
    return redirect('/auth/google');
})->name('login');

/*
|--------------------------------------------------------------------------
| CALLBACKS
|--------------------------------------------------------------------------
*/

Route::post('/midtrans-callback', [PaymentController::class, 'midtransCallback']);
Route::post('/crypto-callback', [PaymentController::class, 'cryptoCallback']);

Route::get('/success', function () {
    return redirect('/licenses');
});
