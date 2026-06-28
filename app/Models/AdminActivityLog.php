<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'admin_name',
        'admin_email',
        'section',
        'action',
        'subject_type',
        'subject_id',
        'subject_label',
        'details',
        'method',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public static function actionLabels(): array
    {
        return [
            'admin.products.store' => 'Created product',
            'admin.products.update' => 'Updated product',
            'admin.products.destroy' => 'Deleted product',
            'admin.products.important-note.update' => 'Updated product note',
            'admin.products.packages.store' => 'Added product package',
            'admin.packages.update' => 'Updated product package',
            'admin.packages.destroy' => 'Deleted product package',
            'admin.categories.store' => 'Created category',
            'admin.categories.update' => 'Updated category',
            'admin.categories.destroy' => 'Deleted category',
            'admin.license-stocks.store' => 'Added license stock',
            'admin.license-stocks.update' => 'Updated license stock',
            'admin.license-stocks.destroy' => 'Deleted license stock',
            'admin.downloads.store' => 'Created download',
            'admin.downloads.update' => 'Updated download',
            'admin.downloads.destroy' => 'Deleted download',
            'admin.vouchers.store' => 'Created voucher',
            'admin.vouchers.update' => 'Updated voucher',
            'admin.vouchers.destroy' => 'Deleted voucher',
            'admin.orders.mark-paid' => 'Marked order paid',
            'admin.orders.resync-license' => 'Resynced order licenses',
        ];
    }

    public static function sectionOptions(): array
    {
        return [
            'catalog' => 'Catalog',
            'categories' => 'Categories',
            'stock' => 'License Stock',
            'downloads' => 'Downloads',
            'vouchers' => 'Vouchers',
            'orders' => 'Orders',
        ];
    }

    public static function sectionForAction(string $action): string
    {
        return match (true) {
            str_starts_with($action, 'admin.products.'),
            str_starts_with($action, 'admin.packages.') => 'catalog',
            str_starts_with($action, 'admin.categories.') => 'categories',
            str_starts_with($action, 'admin.license-stocks.') => 'stock',
            str_starts_with($action, 'admin.downloads.') => 'downloads',
            str_starts_with($action, 'admin.vouchers.') => 'vouchers',
            str_starts_with($action, 'admin.orders.') => 'orders',
            default => 'admin',
        };
    }

    public function getActionLabelAttribute(): string
    {
        return self::actionLabels()[$this->action]
            ?? (string) str($this->action)->after('admin.')->replace('.', ' ')->title();
    }

    public function getSectionLabelAttribute(): string
    {
        return self::sectionOptions()[$this->section]
            ?? (string) str($this->section)->replace('-', ' ')->title();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
