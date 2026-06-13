<?php

use App\Models\LicenseStock;
use App\Services\DirectCryptoOrderVerifier;
use App\Services\PakasirOrderVerifier;
use App\Services\PendingOrderExpirationService;
use App\Services\StockReservationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('license-stocks:purge-unsold {--execute : Delete the matching unsold license stocks} {--seeded-only : Only target known placeholder stock prefixes}', function () {
    $query = LicenseStock::query()->available();

    if ($this->option('seeded-only')) {
        $prefixes = [
            'AURORA-1D-',
            'AURORA-3D-',
            'AURORA-7D-',
            'AURORA-30D-',
            'XG-7D-',
            'DRIP-ROOT-1D-',
            'DRIP-ROOT-3D-',
            'DRIP-ROOT-7D-',
            'DRIP-ROOT-15D-',
            'DRIP-ROOT-30D-',
            'DRIP-ROOT-1M-',
            'DRIP-NONROOT-1D-',
            'DRIP-NONROOT-3D-',
            'DRIP-NONROOT-7D-',
            'DRIP-NONROOT-15D-',
            'DRIP-NONROOT-30D-',
            'DRIP-NONROOT-1M-',
            'FLUORITE-FF-1D-',
            'FLUORITE-FF-7D-',
            'FLUORITE-FF-30D-',
            'FLUORITE-FF-1M-',
            'FLUORITE-ML-1D-',
            'FLUORITE-ML-7D-',
            'FLUORITE-ML-30D-',
            'FLUORITE-ML-1M-',
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

Artisan::command('orders:scan-crypto {--limit=50 : Maximum pending crypto orders to scan}', function () {
    $limit = max(1, (int) $this->option('limit'));
    $summary = app(DirectCryptoOrderVerifier::class)->scanPending($limit);

    $this->info('Direct crypto scan complete.');
    $this->line("Checked: {$summary['checked']}");
    $this->line("Paid: {$summary['paid']}");
    $this->line("Amount mismatch: {$summary['mismatch']}");
    $this->line("Still pending: {$summary['pending']}");

    return self::SUCCESS;
})->purpose('Scan pending direct stablecoin orders and fulfill exact on-chain matches');

Artisan::command('orders:scan-pakasir {--limit=50 : Maximum recent Pakasir orders to reconcile}', function () {
    $limit = max(1, (int) $this->option('limit'));
    $summary = app(PakasirOrderVerifier::class)->scanRecent($limit);

    $this->info('Pakasir reconciliation complete.');
    $this->line("Checked: {$summary['checked']}");
    $this->line("Paid: {$summary['paid']}");
    $this->line("Cancelled: {$summary['cancelled']}");
    $this->line("Still pending: {$summary['pending']}");

    return self::SUCCESS;
})->purpose('Reconcile recent Pakasir transactions with the provider status API');

Artisan::command('orders:release-expired-reservations', function () {
    $released = app(StockReservationService::class)->releaseExpiredReservations();

    $this->info("Released {$released} expired stock reservations.");

    return self::SUCCESS;
})->purpose('Release expired unsold license stock reservations');

Artisan::command('orders:expire-pending {--limit=500 : Maximum expired pending orders to cancel}', function () {
    $limit = max(1, (int) $this->option('limit'));
    $summary = app(PendingOrderExpirationService::class)->expire(limit: $limit);

    $this->info('Expired pending order cleanup complete.');
    $this->line("Cancelled: {$summary['cancelled']}");
    $this->line("QRIS: {$summary['pakasir']}");
    $this->line("Crypto: {$summary['crypto']}");

    return self::SUCCESS;
})->purpose('Cancel expired pending orders and release their reserved license stocks');

Schedule::command('orders:expire-pending --limit=500')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('orders:scan-pakasir --limit=100')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('orders:scan-crypto --limit=100')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('orders:release-expired-reservations')
    ->everyMinute()
    ->withoutOverlapping();
