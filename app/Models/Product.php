<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'category_id',
        'name',
        'description',
    ];

    public function features()
    {
        return $this->hasMany(Feature::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function packages()
    {
        return $this->hasMany(Package::class);
    }

    public function licenseStocks()
    {
        return $this->hasMany(LicenseStock::class);
    }

    public function availableLicenseStocks()
    {
        return $this->hasMany(LicenseStock::class)->where('is_sold', false);
    }
}
