<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

use App\Models\Product;
use App\Models\Order;
use App\Models\License;
use App\Models\User;
use App\Models\Category;
use App\Models\Package;

use Laravel\Socialite\Facades\Socialite;

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

Route::get('/product/{id}', function ($id) {
    $product = Product::with(['features', 'packages'])->findOrFail($id);
    return view('product-detail', compact('product'));
});

Route::post('/process-order/{id}', function ($id) {

    $product = Product::findOrFail($id);
    $package = Package::findOrFail(request('package_id'));

    $order = Order::create([
        'product_id' => $product->id,
        'status' => 'pending',
        'payment_method' => 'midtrans',
        'user_id' => auth()->id(),
        'price' => $package->price,
        'package_id' => $package->id
    ]);

    \Midtrans\Config::$serverKey = config('midtrans.serverKey');
    \Midtrans\Config::$isProduction = false;
    \Midtrans\Config::$isSanitized = true;
    \Midtrans\Config::$is3ds = true;

    $params = [
        'transaction_details' => [
            'order_id' => $order->id,
            'gross_amount' => $package->price,
        ]
    ];

    $snapToken = \Midtrans\Snap::getSnapToken($params);

    return response()->json([
        'snapToken' => $snapToken
    ]);
});

Route::post('/midtrans-callback', function (Request $request) {

    $data = $request->all();
    $order = Order::find($data['order_id']);

    if ($data['transaction_status'] == 'settlement' || $data['transaction_status'] == 'capture') {

        $order->status = 'paid';
        $order->save();

        $package = Package::find($order->package_id);

        License::create([
            'product_id' => $order->product_id,
            'user_id' => $order->user_id,
            'license_key' => strtoupper(Str::random(16)),
            'duration' => $package->name
        ]);

        return response()->json(['message' => 'License created']);
    }

    return response()->json(['message' => 'Ignored']);
});

Route::get('/success', function () {

    $license = License::where('user_id', auth()->id())
        ->latest()
        ->first();

    return view('success', compact('license'));
})->middleware('auth');

Route::get('/licenses', function () {
    $licenses = License::where('user_id', auth()->id())
        ->latest()
        ->get();
    return view('licenses', compact('licenses'));
})->middleware('auth');

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

    Auth::login($user);

    return redirect('/');
});

Route::post('/logout', function () {
    Auth::logout();
    return redirect('/');
});

Route::get('/fake-paid/{id}', function ($id) {

    $order = \App\Models\Order::findOrFail($id);

    $order->status = 'paid';
    $order->save();

    \App\Models\License::create([
        'user_id' => auth()->id(),
        'product_id' => $order->product_id,
        'license_key' => strtoupper(\Illuminate\Support\Str::random(16)),
        'duration' => '7 Hari'
    ]);

    return "Order dipaksa PAID & license dibuat 🔥";
});
