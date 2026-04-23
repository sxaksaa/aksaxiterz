<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use App\Models\Product;
use App\Models\Order;
use App\Models\License;
use App\Models\User;
use App\Models\Category;
use App\Models\Package;

use Laravel\Socialite\Facades\Socialite;

/*
|--------------------------------------------------------------------------
| HOME
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
    Route::post('/process-order/{id}', function ($id) {

        $user = Auth::user();
        $packageId = request('package_id');

        // 🔥 CANCEL SEMUA ORDER PENDING (KONSISTEN DENGAN CRYPTO)
        Order::where('user_id', $user->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);

        $product = Product::findOrFail($id);
        $package = Package::findOrFail($packageId);

        // 🔥 CREATE ORDER BARU
        $orderId = 'ORD-' . strtoupper(Str::random(10));

        Order::create([
            'order_id' => $orderId,
            'product_id' => $product->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'payment_method' => 'midtrans',
            'price' => $package->price,
            'package_id' => $package->id
        ]);

        // 🔥 MIDTRANS CONFIG
        \Midtrans\Config::$serverKey = config('midtrans.serverKey');

        $params = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => $package->price,
            ]
        ];

        $snapToken = \Midtrans\Snap::getSnapToken($params);

        return view('midtrans-pay', compact('snapToken'));
    });

    // 🔥 CRYPTO
    Route::post('/pay-crypto/{product}', function ($productId) {

        $user = Auth::user();
        $packageId = request('package_id');
        $coin = request('coin');

        // 🔥 CANCEL ORDER LAMA
        Order::where('user_id', $user->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);

        $product = Product::findOrFail($productId);
        $package = Package::findOrFail($packageId);

        // 🔥 CREATE ORDER BARU
        $orderId = 'ORD-' . strtoupper(Str::random(10));

        Order::create([
            'order_id' => $orderId,
            'product_id' => $product->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'payment_method' => 'crypto',
            'price' => $package->price_usdt,
            'package_id' => $package->id
        ]);

        // =========================
        // 🔥 STEP 1: VALIDASI (/payment)
        // =========================
        $check = Http::withHeaders([
            'x-api-key' => config('services.nowpayments.key')
        ])->post(config('services.nowpayments.url') . '/payment', [

            "price_amount" => $package->price_usdt,
            "price_currency" => "usd",
            "pay_currency" => $coin,
        ]);

        $checkData = $check->json();

        // ❌ kalau gagal → STOP (NO REDIRECT)
        if (isset($checkData['message'])) {

            $msg = $checkData['message'];

            if (str_contains($msg, 'less than minimal')) {
                $msg = "Minimum payment is higher. Try another network 🙏";
            }

            Order::where('order_id', $orderId)->update(['status' => 'cancelled']);

            return back()->withErrors(['payment' => $msg]);
        }

        // =========================
        // 🔥 STEP 2: BUAT INVOICE (/invoice)
        // =========================
        $response = Http::withHeaders([
            'x-api-key' => config('services.nowpayments.key')
        ])->post(config('services.nowpayments.url') . '/invoice', [

            "price_amount" => $package->price_usdt,
            "price_currency" => "usd",
            "pay_currency" => $coin,

            "order_id" => $orderId,
            "order_description" => $product->name . ' - ' . $package->name,

            "ipn_callback_url" => "https://YOUR-NGROK.ngrok-free.app/crypto-callback",

            "success_url" => url('/licenses'),
            "cancel_url" => url('/'),
        ]);

        $data = $response->json();
        Order::where('order_id', $orderId)->update([
            'payment_url' => $data['invoice_url']
        ]);
        // ❌ safety check
        if (!isset($data['invoice_url'])) {

            Order::where('order_id', $orderId)->update(['status' => 'cancelled']);

            return back()->with('error', 'Payment failed');
        }

        // ✅ baru redirect
        return redirect($data['invoice_url']);
    });
});

/*
|--------------------------------------------------------------------------
| MIDTRANS CALLBACK
|--------------------------------------------------------------------------
*/
Route::post('/midtrans-callback', function (Request $request) {

    $data = $request->all();

    $order = Order::where('order_id', $data['order_id'])->first();

    if (!$order) return response()->json(['error' => 'order not found']);

    if (in_array($data['transaction_status'], ['settlement', 'capture'])) {

        $order->update(['status' => 'paid']);

        License::create([
            'product_id' => $order->product_id,
            'user_id' => $order->user_id,
            'license_key' => strtoupper(Str::random(16)),
            'duration' => Package::find($order->package_id)->name,
            'order_id' => $order->order_id
        ]);
    }

    return response()->json(['message' => 'ok']);
});

/*
|--------------------------------------------------------------------------
| CRYPTO CALLBACK
|--------------------------------------------------------------------------
*/
Route::post('/crypto-callback', function () {

    $data = request()->all();

    Log::info('CRYPTO CALLBACK:', $data);

    if (!isset($data['payment_status'])) {
        return response()->json(['status' => 'no status']);
    }

    if ($data['payment_status'] !== 'finished') {
        return response()->json(['status' => 'not finished']);
    }

    $orderId = $data['order_id'] ?? null;

    $order = Order::where('order_id', $orderId)->first();

    if (!$order) {
        return response()->json(['status' => 'order not found']);
    }

    $order->update(['status' => 'paid']);

    License::create([
        'user_id' => $order->user_id,
        'product_id' => $order->product_id,
        'license_key' => strtoupper(Str::random(16)),
        'duration' => Package::find($order->package_id)->name,
        'order_id' => $order->order_id
    ]);

    return response()->json(['status' => 'success']);
});

/*
|--------------------------------------------------------------------------
| AUTO REFRESH CHECK
|--------------------------------------------------------------------------
*/
Route::get('/check-order', function () {

    $order = Order::where('user_id', auth()->id())
        ->latest()
        ->first();

    if (!$order) {
        return response()->json(['status' => null]);
    }

    // 🔥 HITUNG SISA WAKTU (15 menit)
    $expireAt = \Carbon\Carbon::parse($order->created_at)->addMinutes(15);
    $now = \Carbon\Carbon::now();

    $remaining = $expireAt->diffInSeconds($now, false);

    // 🔥 kalau sudah lewat → cancel
    if ($remaining <= 0 && $order->status === 'pending') {
        $order->update(['status' => 'cancelled']);
    }

    return response()->json([
        'status' => $order->status,
        'remaining' => max(0, $remaining)
    ]);
})->middleware('auth');

/*
|--------------------------------------------------------------------------
| LICENSE PAGE
|--------------------------------------------------------------------------
*/
Route::get('/licenses', function () {
    $licenses = License::where('user_id', auth()->id())->latest()->get();
    return view('licenses', compact('licenses'));
})->middleware('auth');

/*
|--------------------------------------------------------------------------
| GOOGLE LOGIN
|--------------------------------------------------------------------------
*/
Route::get('/auth/google', function () {
    return Socialite::driver('google')->redirect();
});

Route::get('/auth/google/callback', function () {

    $googleUser = Socialite::driver('google')->user();

    $user = User::updateOrCreate([
        'email' => $googleUser->email,
    ], [
        'name' => $googleUser->name,
        'password' => bcrypt('randompassword')
    ]);

    Auth::login($user, true);

    return redirect('/');
});

/*
|--------------------------------------------------------------------------
| LOGOUT
|--------------------------------------------------------------------------
*/
Route::post('/logout', function () {
    Auth::logout();
    return redirect('/');
});

Route::get('/orders', function () {

    $orders = Order::with(['product', 'package'])
        ->where('user_id', auth()->id())
        ->latest()
        ->get();

    return view('orders', compact('orders'));
})->middleware('auth');


Route::get('/api/products', function (Request $request) {

    $query = \App\Models\Product::with('packages');

    if ($request->search) {
        $query->where('name', 'like', '%' . $request->search . '%');
    }

    if ($request->category) {
        $category = \App\Models\Category::where('slug', $request->category)->first();
        if ($category) {
            $query->where('category_id', $category->id);
        }
    }

    $products = $query->get();

    return view('partials.product-card', compact('products'));
});
