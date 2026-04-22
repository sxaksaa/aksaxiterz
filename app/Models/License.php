<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Product;

class License extends Model
{
    protected $fillable = [
        'product_id',
        'user_id',
        'license_key',
        'duration'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}