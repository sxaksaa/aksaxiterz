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

    $products = $query->get();

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
        $packageId = request('package_id'); // 🔥 TAMBAHAN
        // 🔥 AMBIL ORDER VALID SAJA
        $existing = Order::where('user_id', $user->id)
            ->where('status', 'pending')
            ->whereNotNull('order_id')
            ->latest()
            ->first();

        // ✅ lanjut bayar midtrans
        if ($existing && $existing->order_id) {

            // 🔥 kalau paket sama → lanjut
            if ($existing->package_id == $packageId) {

                \Midtrans\Config::$serverKey = config('midtrans.serverKey');

                $params = [
                    'transaction_details' => [
                        'order_id' => $existing->order_id,
                        'gross_amount' => $existing->price,
                    ]
                ];

                $snapToken = \Midtrans\Snap::getSnapToken($params);

                return view('midtrans-pay', compact('snapToken'));
            }

            // 🔥 kalau beda paket → cancel APAPUN metodenya
            $existing->update(['status' => 'cancelled']);
        }

        $product = Product::findOrFail($id);
        $package = Package::findOrFail(request('package_id'));

        // 🔥 PASTI ADA ORDER_ID
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
        $packageId = request('package_id'); // 🔥 TAMBAHAN
        $existing = Order::where('user_id', $user->id)
            ->where('status', 'pending')
            ->whereNotNull('order_id')
            ->latest()
            ->first();

        // ✅ lanjut bayar crypto
        if ($existing && $existing->order_id) {

            if ($existing->package_id == $packageId) {
                return redirect()->away("https://nowpayments.io/payment/?iid=" . $existing->order_id);
            }

            $existing->update(['status' => 'cancelled']);
        }

        $product = Product::findOrFail($productId);
        $package = Package::findOrFail(request('package_id'));
        $coin = request('coin');

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

        $response = Http::withHeaders([
            'x-api-key' => config('services.nowpayments.key')
        ])->post(config('services.nowpayments.url') . '/invoice', [

            "price_amount" => $package->price_usdt,
            "price_currency" => $coin,
            "pay_currency" => $coin,

            "order_id" => $orderId,
            "order_description" => $product->name . ' - ' . $package->name,

            "ipn_callback_url" => "https://YOUR-NGROK.ngrok-free.app/crypto-callback",

            "success_url" => url('/licenses'),
            "cancel_url" => url('/'),
        ]);

        $data = $response->json();

        if (!isset($data['invoice_url'])) {
            return $data;
        }

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

    return response()->json([
        'status' => $order?->status
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
