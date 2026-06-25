<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    public const STATUS_READY = 'ready';

    public const STATUS_UPDATING = 'updating';

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'status',
        'description',
        'important_note',
    ];

    public static function statusOptions(): array
    {
        return [
            self::STATUS_READY => 'Ready',
            self::STATUS_UPDATING => 'Updating',
        ];
    }

    public function getStatusLabelAttribute(): string
    {
        return self::statusOptions()[$this->status] ?? self::statusOptions()[self::STATUS_READY];
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return $this->status === self::STATUS_UPDATING
            ? 'product-status-badge-updating'
            : 'product-status-badge-ready';
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
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

    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function reviews()
    {
        return $this->hasMany(ProductReview::class);
    }

    public function availableLicenseStocks()
    {
        return $this->hasMany(LicenseStock::class)->available();
    }
}
