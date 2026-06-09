<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicenseStock extends Model
{
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
                    ->orWhereNull('reserved_until')
                    ->orWhere('reserved_until', '<=', now());
            });
    }

    public function scopeReserved($query)
    {
        return $query
            ->where('is_sold', false)
            ->whereNotNull('reserved_order_id')
            ->where('reserved_until', '>', now());
    }

    public function isReserved(): bool
    {
        return ! $this->is_sold &&
            filled($this->reserved_order_id) &&
            $this->reserved_until &&
            $this->reserved_until->isFuture();
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
