<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicenseStock extends Model
{
    protected $fillable = [
        'license_key',
        'product_id',
        'package_id',
        'is_sold',
    ];

    protected $casts = [
        'is_sold' => 'boolean',
    ];
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
