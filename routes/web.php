<?php

use App\Http\Controllers\Admin\DownloadController;
use App\Http\Controllers\Admin\LicenseStockController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VoucherController as AdminVoucherController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\VoucherController;
use App\Models\Category;
use App\Models\DownloadItem;
use App\Models\License;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\PendingOrderExpirationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

/*
|--------------------------------------------------------------------------
| HOME & API PRODUCTS
|--------------------------------------------------------------------------
*/

Route::get('/', function (Request $request) {
    $categoryOrder = "CASE slug WHEN 'pc' THEN 1 WHEN 'mobile' THEN 2 WHEN 'android' THEN 3 WHEN 'ios' THEN 4 ELSE 99 END";

    $categories = Category::whereIn('slug', ['pc', 'mobile', 'android', 'ios'])
        ->orderByRaw($categoryOrder)
        ->orderBy('name')
        ->get();

    $query = Product::with([
        'category',
        'packages' => fn ($query) => $query->withCount('availableLicenseStocks')->orderBy('price'),
    ])
        ->withCount('availableLicenseStocks')
        ->withExists(['availableLicenseStocks as has_available_stock'])
        ->orderByDesc('has_available_stock')
        ->orderBy('name');

    if ($request->category === 'mobile') {
        $query->whereHas('category', fn ($query) => $query->whereIn('slug', ['android', 'ios']));
    } elseif ($request->category) {
        $category = Category::where('slug', $request->category)->first();

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
    $query = Product::with([
        'category',
        'packages' => fn ($query) => $query->withCount('availableLicenseStocks')->orderBy('price'),
    ])
        ->withCount('availableLicenseStocks')
        ->withExists(['availableLicenseStocks as has_available_stock'])
        ->orderByDesc('has_available_stock')
        ->orderBy('name');

    if ($request->search) {
        $query->where('name', 'like', '%'.$request->search.'%');
    }

    if ($request->category === 'mobile') {
        $query->whereHas('category', fn ($query) => $query->whereIn('slug', ['android', 'ios']));
    } elseif ($request->category) {
        $category = Category::where('slug', $request->category)->first();

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
    $downloads = collect();

    if (Schema::hasTable('download_items')) {
        $downloads = DownloadItem::query()
            ->orderBy('name')
            ->get()
            ->map(fn (DownloadItem $download) => $download->publicPayload())
            ->values();
    }

    if ($downloads->isEmpty()) {
        $downloads = collect(config('links.downloads', []))
            ->filter(fn ($download) => filled($download['name'] ?? null))
            ->sortBy(fn ($download) => Str::lower((string) ($download['name'] ?? '')))
            ->values();
    }

    $discordUrl = config('links.discord_url');

    return view('downloads', compact('downloads', 'discordUrl'));
});

Route::get('/guides', function () {
    $guides = collect(config('guides.items', []))
        ->filter(fn ($guide) => filled($guide['slug'] ?? null) && filled($guide['title'] ?? null))
        ->values();

    return view('guides.index', [
        'guides' => $guides,
        'updatedAt' => config('guides.updated_at'),
    ]);
})->name('guides.index');

Route::get('/guides/{slug}', function (string $slug) {
    $guide = collect(config('guides.items', []))
        ->firstWhere('slug', $slug);

    abort_if(! $guide, 404);

    return view('guides.show', [
        'guide' => $guide,
        'relatedGuides' => collect(config('guides.items', []))
            ->where('slug', '!=', $slug)
            ->take(3)
            ->values(),
        'updatedAt' => config('guides.updated_at'),
    ]);
})->name('guides.show');

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
Route::get('/product/{product}', function (string $product) {
    $product = Product::with([
        'category',
        'packages' => fn ($query) => $query->withCount('availableLicenseStocks')->orderBy('price'),
    ])
        ->withCount('availableLicenseStocks')
        ->where('slug', $product)
        ->firstOrFail();

    return view('product-detail', compact('product'));
})->where('product', '[A-Za-z0-9-]+')->name('products.show');

/*
|--------------------------------------------------------------------------
| PAYMENT (WAJIB LOGIN)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
    Route::post('/cart/items/{product}', [CartController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('cart.items.store');
    Route::patch('/cart/items/{cartItem}', [CartController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('cart.items.update');
    Route::delete('/cart/items/{cartItem}', [CartController::class, 'destroy'])
        ->middleware('throttle:30,1')
        ->name('cart.items.destroy');
    Route::delete('/cart', [CartController::class, 'clear'])
        ->middleware('throttle:10,1')
        ->name('cart.clear');
    Route::post('/cart/voucher/preview', [CartController::class, 'previewVoucher'])
        ->middleware('throttle:10,1')
        ->name('cart.vouchers.preview');
    Route::post('/cart/checkout', [PaymentController::class, 'checkoutCart'])
        ->middleware('throttle:20,1')
        ->name('cart.checkout');

    Route::post('/voucher/preview', [VoucherController::class, 'preview'])
        ->middleware('throttle:10,1')
        ->name('vouchers.preview');

    // Pay again
    Route::post('/pay-again/{id}', [PaymentController::class, 'payAgain'])
        ->middleware('throttle:10,1');
    Route::post('/cancel-order/{id}', [PaymentController::class, 'cancelOrder'])
        ->middleware('throttle:20,1');

    // Pakasir
    Route::post('/process-order/{id}', [PaymentController::class, 'payPakasir'])
        ->middleware('throttle:20,1');
    Route::post('/sync-pakasir-order/{orderId}', [PaymentController::class, 'syncPakasirOrder'])
        ->middleware('throttle:10,1');
    Route::post('/sync-crypto-order/{orderId}', [PaymentController::class, 'syncCryptoOrder'])
        ->middleware('throttle:20,1');
    Route::post('/sync-binance-pay-order/{orderId}', [PaymentController::class, 'syncBinancePayOrder'])
        ->middleware('throttle:20,1');

    // Crypto
    Route::post('/pay-crypto/{id}', [PaymentController::class, 'payCrypto'])
        ->middleware('throttle:20,1');
    Route::post('/pay-binance/{id}', [PaymentController::class, 'payBinance'])
        ->middleware('throttle:20,1');

    // Check latest order for polling.
    Route::get('/check-order', function (PendingOrderExpirationService $pendingOrderExpirationService) {
        $pendingOrderExpirationService->expire((int) auth()->id());

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
                    $order->status === 'pending' &&
                    ! $order->expired_at,
                'can_sync_binance_pay' => $order->payment_method === 'binance_pay' &&
                    $order->status === 'pending',
            ]);
        }

        $remaining = Carbon::now()->diffInSeconds($order->expired_at, false);
        $cryptoGraceSeconds = max(0, (int) config('services.crypto_direct.grace_minutes', 2)) * 60;
        $binancePayVerifySeconds = max(0, (int) config('services.binance.pay.self_service_verify_minutes', 60)) * 60;

        return response()->json([
            'status' => $order->status,
            'remaining' => max(0, (int) $remaining),
            'order_id' => $order->order_id,
            'payment_method' => $order->payment_method,
            'can_sync_crypto' => $order->payment_method === 'crypto' &&
                $order->status === 'pending' &&
                $remaining > -$cryptoGraceSeconds,
            'can_sync_binance_pay' => $order->payment_method === 'binance_pay' &&
                in_array($order->status, ['pending', 'cancelled'], true) &&
                $remaining > -$binancePayVerifySeconds,
        ]);
    })->middleware('throttle:30,1');

    // License
    Route::get('/licenses', function () {
        $licenses = License::with(['product', 'orderItem'])->where('user_id', auth()->id())->latest()->get();

        return view('licenses', compact('licenses'));
    });

    // Orders
    Route::get('/orders', function (PendingOrderExpirationService $pendingOrderExpirationService) {
        $pendingOrderExpirationService->expire((int) auth()->id());

        $orderStats = [
            'total' => Order::where('user_id', auth()->id())->count(),
            'paid' => Order::where('user_id', auth()->id())->where('status', 'paid')->count(),
            'pending' => Order::where('user_id', auth()->id())->where('status', 'pending')->count(),
        ];

        $orders = Order::with(['product', 'package', 'items.product', 'items.package'])
            ->withCount('licenses')
            ->where('user_id', auth()->id())
            ->latest()
            ->paginate(8)
            ->withPath('/orders');

        return view('orders', compact('orders', 'orderStats'));
    });

    Route::get('/orders-fragment', function (PendingOrderExpirationService $pendingOrderExpirationService) {
        $pendingOrderExpirationService->expire((int) auth()->id());

        $orders = Order::with(['product', 'package', 'items.product', 'items.package'])
            ->withCount('licenses')
            ->where('user_id', auth()->id())
            ->latest()
            ->paginate(8)
            ->withPath('/orders');

        return view('partials.orders-list', compact('orders'));
    })->middleware('throttle:30,1');
});

Route::middleware(['auth', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', fn () => redirect()->route('admin.license-stocks.index'))->name('dashboard');
        Route::get('/license-stocks', [LicenseStockController::class, 'index'])->name('license-stocks.index');
        Route::post('/license-stocks', [LicenseStockController::class, 'store'])->name('license-stocks.store');
        Route::patch('/license-stocks/{licenseStock}', [LicenseStockController::class, 'update'])->name('license-stocks.update');
        Route::delete('/license-stocks/{licenseStock}', [LicenseStockController::class, 'destroy'])->name('license-stocks.destroy');
        Route::get('/downloads', [DownloadController::class, 'index'])->name('downloads.index');
        Route::post('/downloads', [DownloadController::class, 'store'])->name('downloads.store');
        Route::patch('/downloads/{download}', [DownloadController::class, 'update'])->name('downloads.update');
        Route::delete('/downloads/{download}', [DownloadController::class, 'destroy'])->name('downloads.destroy');
        Route::get('/vouchers', [AdminVoucherController::class, 'index'])->name('vouchers.index');
        Route::post('/vouchers', [AdminVoucherController::class, 'store'])->name('vouchers.store');
        Route::patch('/vouchers/{voucher}', [AdminVoucherController::class, 'update'])->name('vouchers.update');
        Route::delete('/vouchers/{voucher}', [AdminVoucherController::class, 'destroy'])->name('vouchers.destroy');
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::patch('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
        Route::patch('/products/{product}/important-note', [ProductController::class, 'updateImportantNote'])
            ->name('products.important-note.update');
        Route::post('/products/{product}/packages', [ProductController::class, 'storePackage'])->name('products.packages.store');
        Route::patch('/packages/{package}', [ProductController::class, 'updatePackage'])->name('packages.update');
        Route::delete('/packages/{package}', [ProductController::class, 'destroyPackage'])->name('packages.destroy');
        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{order}/mark-paid', [OrderController::class, 'markPaid'])->name('orders.mark-paid');
        Route::post('/orders/{order}/resync-license', [OrderController::class, 'resyncLicense'])->name('orders.resync-license');
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
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
})->middleware('throttle:60,1');

Route::get('/auth/google/callback', function (Request $request) use ($isSafeLoginRedirect) {

    try {
        $googleUser = Socialite::driver('google')->user();
    } catch (InvalidStateException $e) {
        Log::warning('GOOGLE LOGIN STATE MISMATCH, rejecting callback', [
            'host' => $request->getHost(),
            'has_session_cookie' => $request->hasCookie(config('session.cookie')),
        ]);

        Cookie::queue(Cookie::forget('login_redirect'));

        return redirect('/')->withErrors([
            'auth' => 'Login session expired. Please try signing in again.',
        ]);
    }

    $googlePayload = $googleUser->user ?? [];

    if (array_key_exists('verified_email', $googlePayload) && ! $googlePayload['verified_email']) {
        abort(403, 'Google email is not verified.');
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
})->middleware('throttle:120,1');

Route::post('/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

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

Route::post('/pakasir-callback', [PaymentController::class, 'pakasirCallback'])
    ->middleware('throttle:120,1');

Route::get('/success', function () {
    return redirect('/licenses');
});
