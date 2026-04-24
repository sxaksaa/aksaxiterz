<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

use App\Models\Product;
use App\Models\Order;
use App\Models\License;
use App\Models\User;
use App\Models\Category;
use App\Models\Package;
use App\Models\LicenseStock;

use App\Http\Controllers\PaymentController;

use Laravel\Socialite\Facades\Socialite;

/*
|--------------------------------------------------------------------------
| HOME & API PRODUCTS
|--------------------------------------------------------------------------
*/

Route::get('/', function (Request $request) {
    $categories = Category::all();
    $query = Product::query();

    if ($request->category) {
        $category = Category::where('slug', $request->category)->first();
        if ($category) {
            $query->where('category_id', $category->id);
        }
    }

    if ($request->search) {
        $query->where('name', 'like', '%' . $request->search . '%');
    }

    $products = $query->with('packages')->get();

    foreach ($products as $product) {
        $product->stock = LicenseStock::where('product_id', $product->id)
            ->where('is_sold', false)
            ->count();
    }
    return view('home', compact('categories', 'products'));
});

Route::get('/api/products', function (Request $request) {
    $query = Product::with('packages');

    if ($request->search) {
        $query->where('name', 'like', '%' . $request->search . '%');
    }

    if ($request->category) {
        $category = Category::where('slug', $request->category)->first();
        if ($category) {
            $query->where('category_id', $category->id);
        }
    }

    $products = $query->get();

    foreach ($products as $product) {
        $product->stock = LicenseStock::where('product_id', $product->id)
            ->where('is_sold', false)
            ->count();
    }
    return view('partials.product-card', compact('products'));
});

/*
|--------------------------------------------------------------------------
| PRODUCT DETAIL
|--------------------------------------------------------------------------
*/
Route::get('/product/{id}', function ($id) {
    $product = Product::with(['features', 'packages'])->findOrFail($id);
    return view('product-detail', compact('product'));
});

/*
|--------------------------------------------------------------------------
| PAYMENT (WAJIB LOGIN)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {

    // 🔥 MIDTRANS
    Route::middleware('auth')->group(function () {

        Route::post('/process-order/{id}', [PaymentController::class, 'payMidtrans'])
            ->middleware('throttle:10,1');

        Route::post('/pay-crypto/{product}', [PaymentController::class, 'payCrypto'])
            ->middleware('throttle:10,1');
    });

    // CHECK ORDER
    Route::get('/check-order', function () {

        $order = Order::where('user_id', auth()->id())->latest()->first();

        if (!$order) {
            return response()->json(['status' => null]);
        }

        $expireAt = Carbon::parse($order->created_at)->addMinutes(15);
        $remaining = $expireAt->diffInSeconds(Carbon::now(), false);

        if ($remaining <= 0 && $order->status === 'pending') {
            $order->update(['status' => 'cancelled']);
        }

        return response()->json([
            'status' => $order->status,
            'remaining' => max(0, abs($remaining))
        ]);
    });

    // LICENSE
    Route::get('/licenses', function () {
        $licenses = License::where('user_id', auth()->id())->latest()->get();
        return view('licenses', compact('licenses'));
    });

    // ORDERS
    Route::get('/orders', function () {
        $orders = Order::with(['product', 'package'])
            ->where('user_id', auth()->id())
            ->latest()
            ->get();
        return view('orders', compact('orders'));
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
            'password' => bcrypt(Str::random(16))
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



Route::post('/midtrans-callback', [PaymentController::class, 'midtransCallback']);
Route::post('/crypto-callback', [PaymentController::class, 'cryptoCallback']);

Route::get('/success', function () {
    return redirect('/licenses');
});
