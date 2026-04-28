<?php

use App\Models\LicenseStock;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('license-stocks:purge-unsold {--execute : Delete the matching unsold license stocks} {--seeded-only : Only target known placeholder stock prefixes}', function () {
    $query = LicenseStock::query()->where('is_sold', false);

    if ($this->option('seeded-only')) {
        $prefixes = [
            'AURORA-1D-',
            'AURORA-7D-',
            'AURORA-30D-',
            'XG-7D-',
            'TEST-PAYMENT-',
            'TEST-PAYMENT-1K-',
            'AksaAVN-',
            'AksaXG-',
        ];

        $query->where(function ($nested) use ($prefixes): void {
            foreach ($prefixes as $prefix) {
                $nested->orWhere('license_key', 'like', $prefix.'%');
            }
        });
    }

    $count = (clone $query)->count();
    $soldCount = LicenseStock::where('is_sold', true)->count();

    $this->info("Matching unsold license stocks: {$count}");
    $this->line("Sold license stocks kept: {$soldCount}");

    $rows = (clone $query)
        ->selectRaw('products.name as product_name, packages.name as package_name, count(*) as total')
        ->join('products', 'products.id', '=', 'license_stocks.product_id')
        ->join('packages', 'packages.id', '=', 'license_stocks.package_id')
        ->groupBy('products.name', 'packages.name')
        ->orderBy('products.name')
        ->orderBy('packages.name')
        ->get();

    foreach ($rows as $row) {
        $this->line("{$row->product_name} / {$row->package_name}: {$row->total}");
    }

    if (! $this->option('execute')) {
        $this->warn('Dry run only. Re-run with --execute to delete these unsold stocks.');

        return self::SUCCESS;
    }

    $deleted = DB::transaction(fn () => (clone $query)->delete());

    $this->info("Deleted {$deleted} unsold license stocks.");
    $this->line('Sold orders and delivered licenses were not touched.');

    return self::SUCCESS;
})->purpose('Safely purge unsold placeholder license stocks without touching sold licenses');
