<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\LicenseStock;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CartService
{
    public const MAX_DISTINCT_ITEMS = 10;

    public const MAX_TOTAL_QUANTITY = 25;

    public function items(User $user): Collection
    {
        return CartItem::query()
            ->with(['product.category', 'package'])
            ->where('user_id', $user->id)
            ->oldest('id')
            ->get();
    }

    public function add(User $user, Product $product, Package $package, int $quantity): CartItem
    {
        return DB::transaction(function () use ($user, $product, $package, $quantity): CartItem {
            $this->ensureSellable($product, $package);
            $quantity = $this->normalizeQuantity($quantity);

            $existing = CartItem::query()
                ->where('user_id', $user->id)
                ->where('package_id', $package->id)
                ->lockForUpdate()
                ->first();
            $distinctCount = CartItem::where('user_id', $user->id)->lockForUpdate()->count();
            $currentTotal = (int) CartItem::where('user_id', $user->id)->lockForUpdate()->sum('quantity');
            $newQuantity = ($existing?->quantity ?? 0) + $quantity;

            if (! $existing && $distinctCount >= self::MAX_DISTINCT_ITEMS) {
                throw new \Exception('Your cart can contain up to '.self::MAX_DISTINCT_ITEMS.' different packages.');
            }

            if (($currentTotal + $quantity) > self::MAX_TOTAL_QUANTITY) {
                throw new \Exception('Your cart can contain up to '.self::MAX_TOTAL_QUANTITY.' license keys.');
            }

            $this->ensureStock($package, $newQuantity);

            if ($existing) {
                $existing->update(['quantity' => $newQuantity]);

                return $existing->fresh(['product', 'package']);
            }

            return CartItem::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'package_id' => $package->id,
                'quantity' => $quantity,
            ])->load(['product', 'package']);
        });
    }

    public function update(User $user, CartItem $item, int $quantity): CartItem
    {
        abort_unless((int) $item->user_id === (int) $user->id, 404);

        return DB::transaction(function () use ($user, $item, $quantity): CartItem {
            $quantity = $this->normalizeQuantity($quantity);
            $locked = CartItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $package = Package::with('product')->findOrFail($locked->package_id);

            $this->ensureSellable($package->product, $package);
            $otherTotal = (int) CartItem::where('user_id', $user->id)
                ->whereKeyNot($locked->id)
                ->lockForUpdate()
                ->sum('quantity');

            if (($otherTotal + $quantity) > self::MAX_TOTAL_QUANTITY) {
                throw new \Exception('Your cart can contain up to '.self::MAX_TOTAL_QUANTITY.' license keys.');
            }

            $this->ensureStock($package, $quantity);
            $locked->update(['quantity' => $quantity]);

            return $locked->fresh(['product', 'package']);
        });
    }

    public function remove(User $user, CartItem $item): void
    {
        abort_unless((int) $item->user_id === (int) $user->id, 404);
        $item->delete();
    }

    public function clear(User $user): void
    {
        CartItem::where('user_id', $user->id)->delete();
    }

    public function validateForCheckout(Collection $items): void
    {
        if ($items->isEmpty()) {
            throw new \Exception('Your cart is empty.');
        }

        if ($items->count() > self::MAX_DISTINCT_ITEMS || $items->sum('quantity') > self::MAX_TOTAL_QUANTITY) {
            throw new \Exception('Your cart is above the checkout limit.');
        }

        foreach ($items as $item) {
            if (! $item->product || ! $item->package) {
                throw new \Exception('A product in your cart is no longer available.');
            }

            $this->ensureSellable($item->product, $item->package);
            $this->ensureStock($item->package, max(1, (int) $item->quantity));
        }
    }

    public function signature(Collection $items): string
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw new \LogicException('An application key is required to sign the cart.');
        }

        $snapshot = $items->sortBy('id')->map(fn ($item): array => [
            'id' => (int) $item->id,
            'user_id' => (int) $item->user_id,
            'product_id' => (int) $item->product_id,
            'package_id' => (int) $item->package_id,
            'product_name' => $item->product?->name,
            'package_name' => $item->package?->name,
            'quantity' => (int) $item->quantity,
            'price_idr' => $item->package?->price === null ? null : (int) $item->package->price,
            'price_usdt' => $item->package?->price_usdt === null
                ? null : number_format((float) $item->package->price_usdt, 6, '.', ''),
        ])->values()->all();

        return hash_hmac('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR), $key);
    }

    private function ensureSellable(Product $product, Package $package): void
    {
        if ((int) $package->product_id !== (int) $product->id) {
            throw new \Exception('The selected package does not belong to this product.');
        }

        if (! $product->is_visible) {
            throw new \Exception('This product is not available for purchase.');
        }

        if ($product->status !== Product::STATUS_READY) {
            throw new \Exception('This product is not ready for automatic checkout.');
        }
    }

    private function ensureStock(Package $package, int $quantity): void
    {
        $available = LicenseStock::query()
            ->where('product_id', $package->product_id)
            ->where('package_id', $package->id)
            ->available()
            ->count();

        if ($available < $quantity) {
            throw new \Exception('Only '.$available.' license key(s) remain for '.$package->product?->name.' - '.$package->name.'.');
        }
    }

    private function normalizeQuantity(int $quantity): int
    {
        if ($quantity < 1) {
            throw new \Exception('Select at least one license key.');
        }

        return $quantity;
    }
}
