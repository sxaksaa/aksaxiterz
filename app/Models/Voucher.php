<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    protected $fillable = [
        'code',
        'discount_percent',
        'max_discount',
        'max_discount_usdt',
        'max_discount_usdc',
        'minimum_purchase',
        'usage_limit',
        'per_user_limit',
        'required_package_ids',
        'is_active',
        'starts_at',
        'expires_at',
    ];

    protected $casts = [
        'discount_percent' => 'integer',
        'max_discount' => 'integer',
        'max_discount_usdt' => 'decimal:6',
        'max_discount_usdc' => 'decimal:6',
        'minimum_purchase' => 'integer',
        'usage_limit' => 'integer',
        'per_user_limit' => 'integer',
        'required_package_ids' => 'array',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function requiredPackageIds(): array
    {
        return collect($this->required_package_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    public function hasBundleRequirement(): bool
    {
        return $this->requiredPackageIds() !== [];
    }

    public function availabilityStatus(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }

        if ($this->starts_at?->isFuture()) {
            return 'scheduled';
        }

        if ($this->expires_at && ! $this->expires_at->isFuture()) {
            return 'expired';
        }

        $activeUses = $this->getAttribute('active_uses_count');

        if ($this->usage_limit !== null && $activeUses !== null && (int) $activeUses >= $this->usage_limit) {
            return 'limit_reached';
        }

        return 'active';
    }

    public function availabilityLabel(): string
    {
        return match ($this->availabilityStatus()) {
            'inactive' => 'Inactive',
            'scheduled' => 'Scheduled',
            'expired' => 'Expired',
            'limit_reached' => 'Limit Reached',
            default => 'Active Now',
        };
    }

    public function availabilityBadgeClass(): string
    {
        return match ($this->availabilityStatus()) {
            'active' => 'status-pill-paid',
            'scheduled' => 'status-pill-pending',
            default => 'status-pill-cancelled',
        };
    }
}
