<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'package_id',
        'product_name',
        'package_name',
        'quantity',
        'unit_price_idr',
        'unit_price_usdt',
        'line_total_idr',
        'line_total_usdt',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price_idr' => 'integer',
        'unit_price_usdt' => 'decimal:6',
        'line_total_idr' => 'integer',
        'line_total_usdt' => 'decimal:6',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function licenses()
    {
        return $this->hasMany(License::class);
    }
}
