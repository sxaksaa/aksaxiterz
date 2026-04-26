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
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/*
|--------------------------------------------------------------------------
| HOME & API PRODUCTS
|--------------------------------------------------------------------------
*/

Route::get('/', function (Request $request) {
    $categories = Category::all();
    $query = Product::with(['category', 'packages'])->withCount('availableLicenseStocks');

    if ($request->category) {
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

Route::get('/api/products', function (Request $request) {
    $query = Product::with(['category', 'packages'])->withCount('availableLicenseStocks');

    if ($request->search) {
        $query->where('name', 'like', '%'.$request->search.'%');
    }

    if ($request->category) {
        $category = Category::where('slug', $request->category)->first();
        if ($category) {
            $query->where('category_id', $category->id);
        }
    }

    $products = $query->get();

    return view('partials.product-card', compact('products'));
});

/*
|--------------------------------------------------------------------------
| PRODUCT DETAIL
|--------------------------------------------------------------------------
*/
Route::get('/product/{id}', function ($id) {
    $product = Product::with(['features', 'packages'])
        ->withCount('availableLicenseStocks')
        ->findOrFail($id);

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

    // Midtrans pay page
    Route::get('/midtrans-pay', function () {
        $token = request('token');

        abort_if(blank($token), 404);

        return view('midtrans-pay', compact('token'));
    })->name('midtrans.pay.page');

    // Midtrans
    Route::post('/process-order/{id}', [PaymentController::class, 'payMidtrans'])
        ->middleware('throttle:5,1');

    // Crypto
    Route::post('/pay-crypto/{id}', [PaymentController::class, 'payCrypto'])
        ->middleware('throttle:5,1');

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
            ]);
        }

        $remaining = Carbon::now()->diffInSeconds($order->expired_at, false);

        if ($remaining <= 0 && $order->status === 'pending') {
            $order->update(['status' => 'cancelled']);
        }

        return response()->json([
            'status' => $order->status,
            'remaining' => max(0, (int) $remaining),
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
            ->update(['status' => 'cancelled']);

        $orders = Order::with(['product', 'package'])
            ->where('user_id', auth()->id())
            ->latest()
            ->get();

        return view('orders', compact('orders'));
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

Route::get('/auth/google', function () {
    return Socialite::driver('google')->redirect();
});

Route::get('/auth/google/callback', function () {

    $googleUser = Socialite::driver('google')->user();

    $user = User::updateOrCreate(
        ['email' => $googleUser->email],
        [
            'name' => $googleUser->name,
            'password' => bcrypt(Str::random(16)),
        ]
    );

    Auth::login($user, true);

    return redirect('/');
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
