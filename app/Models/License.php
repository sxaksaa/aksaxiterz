<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class License extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'license_key',
        'duration',
        'order_id',
        'order_item_id',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function resetAttempts()
    {
        return $this->hasMany(LicenseReset::class);
    }

    public function latestSuccessfulReset()
    {
        return $this->hasOne(LicenseReset::class)
            ->where('status', LicenseReset::STATUS_SUCCEEDED)
            ->latestOfMany('succeeded_at');
    }
}
