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
        'payment_method',
        'price',
        'package_id',
        'payment_url',
        'expired_at',
    ];

    protected $casts = [
        'expired_at' => 'datetime',
        'price' => 'decimal:4',
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
}
