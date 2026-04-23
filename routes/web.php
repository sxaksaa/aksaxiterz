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
| CALLBACKS (PUBLIC)
|--------------------------------------------------------------------------
*/

// 🔥 MIDTRANS CALLBACK (SECURE)
Route::post('/midtrans-callback', function (Request $request) {

    $data = $request->all();

    $serverKey = config('midtrans.serverKey');

    $hashed = hash(
        "sha512",
        $data['order_id'] .
            $data['status_code'] .
            $data['gross_amount'] .
            $serverKey
    );

    if ($hashed !== $data['signature_key']) {
        return response()->json(['error' => 'invalid signature']);
    }

    $order = Order::where('order_id', $data['order_id'])->first();

    if (!$order) {
        return response()->json(['error' => 'order not found']);
    }

    if (in_array($data['transaction_status'], ['settlement', 'capture'])) {

        if ($order->status !== 'paid') {

            $order->update(['status' => 'paid']);

            License::create([
                'product_id' => $order->product_id,
                'user_id' => $order->user_id,
                'license_key' => strtoupper(Str::random(16)),
                'duration' => Package::find($order->package_id)->name,
                'order_id' => $order->order_id
            ]);
        }
    }

    return response()->json(['message' => 'ok']);
});

// 🔥 CRYPTO CALLBACK
Route::post('/crypto-callback', function (Request $request) {
    $signature = $request->header('x-nowpayments-sig');

    $expected = hash_hmac(
        'sha512',
        $request->getContent(),
        config('services.nowpayments.key')
    );

    if (!$signature || $signature !== $expected) {
        \Log::warning('INVALID CRYPTO SIGNATURE', $request->all());
        return response()->json(['error' => 'invalid signature']);
    }

    $data = $request->all();

    Log::info('CRYPTO CALLBACK:', $data);

    if (!isset($data['payment_id'])) {
        return response()->json(['status' => 'invalid payment']);
    }

    if (($data['payment_status'] ?? '') !== 'finished') {
        return response()->json(['status' => 'not finished']);
    }

    $order = Order::where('order_id', $data['order_id'] ?? null)->first();

    if (!$order) {
        return response()->json(['status' => 'order not found']);
    }

    if ((float)$data['price_amount'] !== (float)$order->price) {
        return response()->json(['status' => 'invalid amount']);
    }

    if ($order->status !== 'paid') {

        $order->update(['status' => 'paid']);

        License::create([
            'user_id' => $order->user_id,
            'product_id' => $order->product_id,
            'license_key' => strtoupper(Str::random(16)),
            'duration' => Package::find($order->package_id)->name,
            'order_id' => $order->order_id
        ]);
    }

    return response()->json(['status' => 'success']);
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