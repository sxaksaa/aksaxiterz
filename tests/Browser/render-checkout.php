<?php

// CLI-only fixture renderer. The browser runner supplies an isolated SQLite database.
use App\Http\Controllers\PaymentInstructionController;
use App\Models\Category;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || config('database.default') !== 'sqlite' || PHP_SAPI !== 'cli') {
    throw new RuntimeException('Checkout fixtures require the isolated browser test environment.');
}

DB::beginTransaction();
try {
    $user = User::factory()->create();
    $category = Category::create(['name' => 'Checkout Fixture', 'slug' => 'checkout-fixture']);
    $product = Product::create([
        'category_id' => $category->id, 'name' => 'Checkout Fixture', 'slug' => 'checkout-fixture',
        'status' => Product::STATUS_READY, 'is_visible' => true, 'description' => 'Browser test fixture.',
    ]);
    $package = Package::create(['product_id' => $product->id, 'name' => '30 Days', 'price' => 20000, 'price_usdt' => 1.25]);
    LicenseStock::create(['product_id' => $product->id, 'package_id' => $package->id, 'license_key' => 'CHECKOUT-FIXTURE', 'is_sold' => false]);
    Auth::guard()->setUser($user);
    config(['services.gopay_qris.enabled' => true]);
    $request = Request::create(route('checkout.product', ['product' => $product, 'package' => $package->id]));
    $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException('Could not render checkout fixture: '.$response->getStatusCode());
    }
    file_put_contents($argv[1].'/checkout.html', $response->getContent());

    // Render the actual payment view; this order is rolled back with the fixture.
    $order = Order::create([
        'order_id' => 'ORDER-LAYOUT-FIXTURE', 'user_id' => $user->id,
        'product_id' => $product->id, 'package_id' => $package->id,
        'quantity' => 1, 'price' => 1.25, 'status' => 'pending',
        'payment_method' => 'crypto', 'expired_at' => now()->addMinutes(10),
        'payment_payload' => [
            'type' => 'direct_crypto', 'token' => 'USDT', 'network' => 'usdtavaxc',
            'network_label' => 'USDT Avalanche C-Chain (AVAXC)',
            'address' => 'TEST-ADDRESS-NOT-FOR-PAYMENTS', 'amount' => '1.25',
        ],
    ]);
    $paymentRequest = Request::create(route('orders.payment', $order->order_id));
    $paymentRequest->setUserResolver(fn () => $user);
    $paymentView = $app->make(PaymentInstructionController::class)
        ->show($paymentRequest, $order->order_id);
    file_put_contents($argv[1].'/payment.html', $paymentView->render());

} finally {
    DB::rollBack();
}
