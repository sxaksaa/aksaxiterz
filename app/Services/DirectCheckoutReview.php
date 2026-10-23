<?php

namespace App\Services;

use App\Models\Package;
use App\Models\Product;
use App\Models\User;

class DirectCheckoutReview
{
    public function signature(User $user, Product $product, Package $package, int $quantity): string
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw new \LogicException('An application key is required to sign the checkout.');
        }

        return hash_hmac('sha256', json_encode([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'package_id' => (int) $package->id,
            'product_name' => $product->name,
            'package_name' => $package->name,
            'quantity' => $quantity,
            'price_idr' => (int) $package->price,
            'price_usdt' => $package->price_usdt === null
                ? null : number_format((float) $package->price_usdt, 6, '.', ''),
        ], JSON_THROW_ON_ERROR), $key);
    }
}
