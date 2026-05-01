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
        'payment_url',
        'payment_payload',
        'expired_at',
        'replaced_by',
    ];

    protected $casts = [
        'expired_at' => 'datetime',
        'paid_at' => 'datetime',
        'price' => 'decimal:4',
        'payment_payload' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function license()
    {
        return $this->hasOne(License::class, 'order_id', 'order_id');
    }
}
