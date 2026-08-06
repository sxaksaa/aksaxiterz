<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DownloadController;
use App\Http\Controllers\Admin\GopayNotificationEventController;
use App\Http\Controllers\Admin\LicenseStockController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VoucherController as AdminVoucherController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\LicenseResetController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentInstructionController;
use App\Http\Controllers\VoucherController;
use App\Models\Category;
use App\Models\DownloadItem;
use App\Models\License;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Voucher as VoucherModel;
use App\Services\LicenseResetManager;
use App\Services\PendingOrderExpirationService;
use App\Support\OrderStats;
use App\Support\ProductSalesSignals;
use App\Support\RecentPurchaseFeed;
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

$activePromoVoucher = function (?Product $product = null) {
    $vouchers = VoucherModel::query()
        ->withCount([
            'orders as active_uses_count' => fn ($query) => $query->where('status', '!=', 'cancelled'),
        ])
        ->where('is_active', true)
        ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
        ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
        ->orderByDesc('discount_percent')
        ->orderByDesc('max_discount')
        ->get();

    if ($product) {
        $vouchers = $vouchers->filter(function (VoucherModel $voucher) use ($product): bool {
            $requiredProductIds = $voucher->requiredProductIds();

            return $requiredProductIds === [] || in_array((int) $product->id, $requiredProductIds, true);
        });
    }

    return $vouchers->first(fn (VoucherModel $voucher) => $voucher->availabilityStatus() === 'active');
};

Route::get('/', function (Request $request) use ($activePromoVoucher) {
    $categoryOrder = "CASE slug WHEN 'pc' THEN 1 WHEN 'mobile' THEN 2 WHEN 'android' THEN 3 WHEN 'ios' THEN 4 ELSE 99 END";

    $categories = Category::where(function ($query) {
        $query->where('slug', 'mobile')
            ->orWhereHas('products', fn ($query) => $query->visible());
    })
        ->orderByRaw($categoryOrder)
        ->orderBy('name')
        ->get();

    $query = Product::with([
        'category',
        'packages' => fn ($query) => $query->withCount('availableLicenseStocks')->orderBy('price'),
    ])
        ->visible()
        ->withCount('availableLicenseStocks')
        ->withExists(['availableLicenseStocks as has_available_stock'])
        ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [Product::STATUS_READY])
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

    $products = app(ProductSalesSignals::class)->apply($query->get());
    $totalStock = LicenseStock::query()
        ->available()
        ->whereHas('product', fn ($query) => $query->visible()->ready())
        ->count();
    $recentPurchases = app(RecentPurchaseFeed::class)->storefront();
    $promoVoucher = $activePromoVoucher();

    return view('home', compact('categories', 'products', 'totalStock', 'recentPurchases', 'promoVoucher'));
});

$productsFragment = function (Request $request) {
    $query = Product::with([
        'category',
        'packages' => fn ($query) => $query->withCount('availableLicenseStocks')->orderBy('price'),
    ])
        ->visible()
        ->withCount('availableLicenseStocks')
        ->withExists(['availableLicenseStocks as has_available_stock'])
        ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [Product::STATUS_READY])
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

    $products = app(ProductSalesSignals::class)->apply($query->get());

    return view('partials.product-card', compact('products'));
};

Route::get('/products-fragment', $productsFragment)->name('products.fragment');
Route::get('/api/products', $productsFragment);

Route::get('/sitemap.xml', function () {
    $urls = collect([
        ['loc' => url('/'), 'lastmod' => null],
        ['loc' => route('guides.index'), 'lastmod' => config('guides.updated_at')],
        ['loc' => url('/downloads'), 'lastmod' => null],
        ['loc' => route('terms'), 'lastmod' => config('legal.updated_at')],
        ['loc' => route('privacy'), 'lastmod' => config('legal.updated_at')],
        ['loc' => route('refund-policy'), 'lastmod' => config('legal.updated_at')],
        ['loc' => route('faq'), 'lastmod' => config('legal.updated_at')],
        ['loc' => route('contact'), 'lastmod' => config('legal.updated_at')],
    ]);

    Product::query()->visible()->select(['slug', 'updated_at'])->orderBy('id')->each(
        fn (Product $product) => $urls->push([
            'loc' => route('products.show', $product->slug),
            'lastmod' => $product->updated_at?->toDateString(),
        ])
    );

    collect(config('guides.items', []))->each(function (array $guide) use ($urls): void {
        if (filled($guide['slug'] ?? null)) {
            $urls->push([
                'loc' => route('guides.show', $guide['slug']),
                'lastmod' => config('guides.updated_at'),
            ]);
        }
    });

    return response()
        ->view('sitemap', ['urls' => $urls])
        ->header('Content-Type', 'application/xml; charset=UTF-8');
})->name('sitemap');

Route::get('/csrf-token', fn () => response()->json([
    'token' => csrf_token(),
]))->name('csrf-token');

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
Route::get('/faq', fn () => $legalPage('faq'))->name('faq');
Route::get('/contact', fn () => $legalPage('contact'))->name('contact');

/*
|--------------------------------------------------------------------------
| PRODUCT DETAIL
|--------------------------------------------------------------------------
*/
Route::get('/product/{product}', function (string $product) use ($activePromoVoucher) {
    $product = Product::with([
        'category',
        'packages' => fn ($query) => $query->withCount('availableLicenseStocks')->orderBy('price'),
    ])
        ->visible()
        ->withCount('availableLicenseStocks')
        ->where('slug', $product)
        ->firstOrFail();

    app(ProductSalesSignals::class)->apply(collect([$product]));

    $recentPurchases = app(RecentPurchaseFeed::class)->storefront($product);
    $promoVoucher = $activePromoVoucher($product);

    return view('product-detail', compact('product', 'recentPurchases', 'promoVoucher'));
})->where('product', '[A-Za-z0-9-]+')->name('products.show');

/*
|--------------------------------------------------------------------------
| PAYMENT (WAJIB LOGIN)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
    Route::get('/checkout', [CheckoutController::class, 'cart'])->name('checkout.cart');
    Route::get('/checkout/{product}', [CheckoutController::class, 'product'])
        ->where('product', '[A-Za-z0-9-]+')
        ->name('checkout.product');
    Route::post('/checkout/{product}', [PaymentController::class, 'checkoutProduct'])
        ->where('product', '[A-Za-z0-9-]+')
        ->middleware('throttle:20,1')
        ->name('checkout.product.process');
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
    Route::get('/orders/{orderId}/payment', [PaymentInstructionController::class, 'show'])
        ->where('orderId', 'ORDER-[A-Za-z0-9-]+')
        ->name('orders.payment');

    Route::post('/voucher/preview', [VoucherController::class, 'preview'])
        ->middleware('throttle:10,1')
        ->name('vouchers.preview');
    // Pay again
    Route::post('/pay-again/{id}', [PaymentController::class, 'payAgain'])
        ->middleware('throttle:10,1');
    Route::post('/cancel-order/{id}', [PaymentController::class, 'cancelOrder'])
        ->middleware('throttle:20,1');

    Route::post('/pay-gopay-qris/{id}', [PaymentController::class, 'payGopayQris'])
        ->middleware('throttle:20,1')
        ->name('gopay-qris.pay');
    Route::post('/sync-gopay-qris-order/{orderId}', [PaymentController::class, 'syncGopayQrisOrder'])
        ->middleware('throttle:30,1')
        ->name('gopay-qris.sync');
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
                'can_sync_gopay_qris' => $order->payment_method === 'gopay_qris' &&
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
            'can_sync_gopay_qris' => $order->payment_method === 'gopay_qris' &&
                $order->status === 'pending',
        ]);
    })->middleware('throttle:30,1');

    // License
    Route::get('/licenses', function (
        PendingOrderExpirationService $pendingOrderExpirationService,
        LicenseResetManager $licenseResetManager,
    ) {
        $pendingOrderExpirationService->expire((int) auth()->id());

        $licenses = License::with(['product', 'order', 'orderItem', 'latestSuccessfulReset'])
            ->where('user_id', auth()->id())
            ->latest()
            ->get();
        $licenseResetStates = $licenses
            ->mapWithKeys(fn (License $license) => [
                $license->id => $licenseResetManager->state($license),
            ])
            ->all();

        $orderStats = OrderStats::forUser((int) auth()->id());

        return view('licenses', compact('licenses', 'orderStats', 'licenseResetStates'));
    });
    Route::post('/licenses/{license}/reset-hwid', [LicenseResetController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('licenses.reset-hwid');

    // Orders
    Route::get('/orders', function (PendingOrderExpirationService $pendingOrderExpirationService) {
        $pendingOrderExpirationService->expire((int) auth()->id());

        $orderStats = OrderStats::forUser((int) auth()->id());

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

        $orderStats = OrderStats::forUser((int) auth()->id());

        $orders = Order::with(['product', 'package', 'items.product', 'items.package'])
            ->withCount('licenses')
            ->where('user_id', auth()->id())
            ->latest()
            ->paginate(8)
            ->withPath('/orders');

        return view('partials.orders-list', compact('orders', 'orderStats'));
    })->middleware('throttle:30,1');
});

Route::middleware(['auth', 'admin', 'admin.activity'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/activity', [ActivityLogController::class, 'index'])->name('activity.index');
        Route::get('/gopay-events', [GopayNotificationEventController::class, 'index'])->name('gopay-events.index');
        Route::get('/license-stocks', [LicenseStockController::class, 'index'])->name('license-stocks.index');
        Route::post('/license-stocks', [LicenseStockController::class, 'store'])->name('license-stocks.store');
        Route::patch('/license-stocks/{licenseStock}', [LicenseStockController::class, 'update'])->name('license-stocks.update');
        Route::delete('/license-stocks/{licenseStock}', [LicenseStockController::class, 'destroy'])->name('license-stocks.destroy');
        Route::get('/downloads', [DownloadController::class, 'index'])->name('downloads.index');
        Route::post('/downloads', [DownloadController::class, 'store'])->name('downloads.store');
        Route::patch('/downloads/{download}', [DownloadController::class, 'update'])->name('downloads.update');
        Route::delete('/downloads/{download}', [DownloadController::class, 'destroy'])->name('downloads.destroy');
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::patch('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
        Route::get('/vouchers', [AdminVoucherController::class, 'index'])->name('vouchers.index');
        Route::post('/vouchers', [AdminVoucherController::class, 'store'])->name('vouchers.store');
        Route::get('/vouchers/{voucher}', [AdminVoucherController::class, 'show'])->name('vouchers.show');
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
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
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

Route::get('/success', function () {
    return redirect('/licenses');
});
