<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Feature;
use App\Models\Package;

class Product extends Model
{
    public function features()
    {
        return $this->hasMany(Feature::class);
    }

    public function packages()
    {
        return $this->hasMany(Package::class);
    }
}