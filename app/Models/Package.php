<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $fillable = [
        'product_id',
        'name',
        'price',
        'price_usdt',
    ];
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
