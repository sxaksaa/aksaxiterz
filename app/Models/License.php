<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Product;

class License extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'license_key',
        'duration',
        'order_id',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
