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

    public function licenseStocks()
    {
        return $this->hasMany(LicenseStock::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function availableLicenseStocks()
    {
        return $this->hasMany(LicenseStock::class)->available();
    }

    public function durationDays(): ?int
    {
        if (preg_match('/(\d+)\s*year/i', $this->name, $matches)) {
            return ((int) $matches[1]) * 365;
        }

        if (preg_match('/(\d+)\s*month/i', $this->name, $matches)) {
            return ((int) $matches[1]) * 30;
        }

        if (preg_match('/(\d+)\s*(?:day|hari)/i', $this->name, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
