<?php

namespace App\Models;

use App\Support\StorefrontCache;
use Illuminate\Database\Eloquent\Model;

class LicenseStock extends Model
{
    protected static function booted(): void
    {
        $forget = fn (LicenseStock $stock) => StorefrontCache::forgetStock(
            (int) $stock->product_id
        );

        static::saved($forget);
        static::deleted($forget);
    }

    protected $fillable = [
        'license_key',
        'product_id',
        'package_id',
        'is_sold',
        'reserved_order_id',
        'reserved_until',
        'sold_at',
    ];

    protected $casts = [
        'is_sold' => 'boolean',
        'reserved_until' => 'datetime',
        'sold_at' => 'datetime',
    ];

    public function scopeAvailable($query)
    {
        return $query
            ->where('is_sold', false)
            ->where(function ($query): void {
                $query->whereNull('reserved_order_id')
                    ->orWhereDoesntHave('reservedOrder')
                    ->orWhereHas('reservedOrder', fn ($order) => $order->where('status', 'cancelled'))
                    ->orWhere(function ($expiredCrypto): void {
                        $expiredCrypto->where('reserved_until', '<=', now())
                            ->whereHas('reservedOrder', function ($order): void {
                                $order->where('status', 'pending')
                                    ->where('payment_method', 'crypto');
                            });
                    });
            });
    }

    public function scopeReserved($query)
    {
        return $query
            ->where('is_sold', false)
            ->whereNotNull('reserved_order_id')
            ->where(function ($query): void {
                $query->whereHas('reservedOrder', fn ($order) => $order->where('status', 'paid'))
                    ->orWhereHas('reservedOrder', function ($order): void {
                        $order->where('status', 'pending')
                            ->where(function ($method): void {
                                $method->whereNull('payment_method')
                                    ->orWhere('payment_method', '!=', 'crypto');
                            });
                    })
                    ->orWhere(function ($activeCrypto): void {
                        $activeCrypto->where('reserved_until', '>', now())
                            ->whereHas('reservedOrder', function ($order): void {
                                $order->where('status', 'pending')
                                    ->where('payment_method', 'crypto');
                            });
                    });
            });
    }

    public function isReserved(): bool
    {
        if ($this->is_sold || blank($this->reserved_order_id)) {
            return false;
        }

        $order = $this->reservedOrder;

        if (! $order || $order->status === 'cancelled') {
            return false;
        }

        return $order->status === 'paid' ||
            $order->payment_method !== 'crypto' ||
            ($this->reserved_until && $this->reserved_until->isFuture());
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function soldLicense()
    {
        return $this->hasOne(License::class, 'license_key', 'license_key')->latestOfMany();
    }

    public function reservedOrder()
    {
        return $this->belongsTo(Order::class, 'reserved_order_id');
    }
}
