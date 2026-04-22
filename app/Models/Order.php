<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'order_id', // 🔥 INI YANG PENTING
        'user_id',
        'product_id',
        'status',
        'payment_method',
        'price',
        'package_id'
    ];
}