<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    protected $fillable = [
        'code',
        'discount_percent',
        'max_discount',
        'max_discount_usdt',
        'max_discount_usdc',
        'minimum_purchase',
        'usage_limit',
        'per_user_limit',
        'is_active',
        'starts_at',
        'expires_at',
    ];

    protected $casts = [
        'discount_percent' => 'integer',
        'max_discount' => 'integer',
        'max_discount_usdt' => 'decimal:6',
        'max_discount_usdc' => 'decimal:6',
        'minimum_purchase' => 'integer',
        'usage_limit' => 'integer',
        'per_user_limit' => 'integer',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
