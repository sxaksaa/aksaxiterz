<?php

namespace App\Models;

use App\Support\StorefrontCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected static function booted(): void
    {
        $forget = function (Product $product): void {
            StorefrontCache::forgetStock((int) $product->id);
            StorefrontCache::forgetRecentPurchasesForProduct((int) $product->id);
        };

        static::saved($forget);
        static::deleted($forget);
    }

    public const STATUS_READY = 'ready';

    public const STATUS_UPDATING = 'updating';

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'status',
        'is_visible',
        'description',
        'important_note',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
    ];

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_READY);
    }

    public function scopeMatchingSearch(Builder $query, ?string $search): Builder
    {
        $search = trim(mb_substr((string) $search, 0, 200));

        if ($search === '') {
            return $query;
        }

        $terms = preg_split('/[^\pL\pN]+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = array_slice(array_values(array_unique($terms)), 0, 8);

        if ($terms === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($terms): void {
            foreach ($terms as $term) {
                $query->where('name', 'like', '%'.$term.'%');
            }
        });
    }

    public function isReadyForAutomaticCheckout(): bool
    {
        return $this->is_visible && $this->status === self::STATUS_READY;
    }

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

    public function availableLicenseStocks()
    {
        return $this->hasMany(LicenseStock::class)->available();
    }
}
