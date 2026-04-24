<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicenseStock extends Model
{
    protected $fillable = [
        'license_key',
        'product_id',
        'package_id', // tambah ini
        'is_sold'
    ];
}
