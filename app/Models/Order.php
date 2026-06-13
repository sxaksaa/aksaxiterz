<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
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
}
