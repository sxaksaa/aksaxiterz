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
        'sold_at',
    ];

    protected $casts = [
        'is_sold' => 'boolean',
        'sold_at' => 'datetime',
    ];
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function soldLicense()
    {
        return $this->hasOne(License::class, 'license_key', 'license_key')->latestOfMany();
    }
}
