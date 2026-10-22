<?php

namespace App\Models;

use App\Support\StorefrontCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Order extends Model
{
    protected static function booted(): void
    {
        static::saved(function (Order $order): void {
            if (
                $order->wasRecentlyCreated ||
                $order->wasChanged(['status', 'paid_at', 'product_id'])
            ) {
                StorefrontCache::forgetRecentPurchasesForProduct((int) $order->product_id);
            }
        });

        static::deleted(fn (Order $order) => StorefrontCache::forgetRecentPurchasesForProduct(
            (int) $order->product_id
        ));
    }

    protected $fillable = [
        'order_id',
        'product_id',
        'user_id',
        'status',
        'paid_at',
        'payment_method',
        'price',
        'package_id',
        'quantity',
        'voucher_id',
        'payment_url',
        'payment_payload',
        'payment_reference',
        'payment_match_key',
        'expired_at',
        'replaced_by',
    ];

    protected $casts = [
        'expired_at' => 'datetime',
        'paid_at' => 'datetime',
        'price' => 'decimal:6',
        'payment_payload' => 'array',
        'quantity' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function scopeConsumesVoucherQuota(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('status', '!=', 'cancelled')
                ->orWhere(function (Builder $recoverable): void {
                    $recoverable->where('status', 'cancelled')
                        ->whereNotNull('payment_match_key')->where('payment_match_key', '!=', '')
                        ->where(function (Builder $methods): void {
                            foreach (['crypto' => 'services.crypto_direct.recovery_hours',
                                'binance_pay' => 'services.binance.pay.recovery_hours',
                                'gopay_qris' => 'services.gopay_qris.recovery_hours'] as $method => $setting) {
                                $cutoff = now()->subHours(max(1, (int) config($setting, $method === 'gopay_qris' ? 72 : 24)));
                                $methods->orWhere(fn (Builder $invoice) => $invoice->where('payment_method', $method)
                                    ->where(fn (Builder $deadline) => $deadline->whereNull('expired_at')
                                        ->orWhere('expired_at', $method === 'gopay_qris' ? '>=' : '>', $cutoff)));
                            }
                        });
                });
        });
    }

    public function lockVoucherForSettlement(): void
    {
        // Call inside the settlement transaction, before rechecking recovery eligibility.
        // Quotes use the same voucher lock so expiry cannot release a slot mid-settlement.
        if ($this->voucher_id) {
            $this->voucher()->lockForUpdate()->first();
        }
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function license()
    {
        return $this->hasOne(License::class, 'order_id', 'order_id');
    }

    public function licenses()
    {
        return $this->hasMany(License::class, 'order_id', 'order_id')->orderBy('id');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function lineItems()
    {
        if ($this->relationLoaded('items') && $this->items->isNotEmpty()) {
            return $this->items;
        }

        $items = Schema::hasTable('order_items') && $this->exists
            ? $this->items()->get()
            : collect();

        if ($items->isNotEmpty()) {
            return $items;
        }

        if (! $this->product_id || ! $this->package_id) {
            return collect();
        }

        return collect([new OrderItem([
            'order_id' => $this->id,
            'product_id' => $this->product_id,
            'package_id' => $this->package_id,
            'product_name' => $this->product?->name ?? 'Product',
            'package_name' => $this->package?->name ?? 'Package',
            'quantity' => max(1, (int) $this->quantity),
            'unit_price_idr' => (int) ($this->package?->price ?? 0),
            'unit_price_usdt' => (float) ($this->package?->price_usdt ?? 0),
            'line_total_idr' => (int) ($this->package?->price ?? 0) * max(1, (int) $this->quantity),
            'line_total_usdt' => (float) ($this->package?->price_usdt ?? 0) * max(1, (int) $this->quantity),
        ])]);
    }

    public function getItemCountAttribute(): int
    {
        return $this->lineItems()->count();
    }

    public function getTotalQuantityAttribute(): int
    {
        return max(1, (int) $this->lineItems()->sum('quantity'));
    }

    public function cartSignature(): string
    {
        return $this->lineItems()
            ->map(fn (OrderItem $item) => $item->package_id.':'.$item->quantity)
            ->sort()
            ->implode('|');
    }
}
