<?php

use App\Models\LicenseStock;
use App\Models\Order;
use App\Services\BinancePayOrderVerifier;
use App\Services\DirectCryptoOrderVerifier;
use App\Services\PaymentService;
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

Artisan::command('orders:scan-binance-pay {--limit=100 : Maximum recent Binance Pay orders to scan}', function () {
    $limit = max(1, (int) $this->option('limit'));
    $summary = app(BinancePayOrderVerifier::class)->scanPending($limit);

    $this->info('Binance Pay scan complete.');
    $this->line("Checked: {$summary['checked']}");
    $this->line("Paid: {$summary['paid']}");
    $this->line("Still pending: {$summary['pending']}");

    if ($summary['skipped'] ?? false) {
        $this->line('Skipped provider request because another scan ran recently.');
    }

    return self::SUCCESS;
})->purpose('Match personal Binance Pay transfers and fulfill exact-amount orders');

Artisan::command('orders:diagnose-binance {orderId : Existing direct-crypto order ID}', function () {
    $order = Order::where('order_id', (string) $this->argument('orderId'))->first();

    if (! $order) {
        $this->error('Order not found.');

        return self::FAILURE;
    }

    $payload = $order->payment_payload;

    if ($order->payment_method !== 'crypto' || ! is_array($payload) || ($payload['type'] ?? null) !== 'direct_crypto') {
        $this->error('Order is not a direct-crypto order.');

        return self::FAILURE;
    }

    $probe = new Order([
        'order_id' => $order->order_id,
        'payment_method' => $order->payment_method,
        'payment_payload' => $payload,
    ]);
    $probe->created_at = $order->created_at;

    $fallback = config('services.binance.deposit_fallback', []);

    if (! is_array($fallback) || ! ($fallback['enabled'] ?? false)) {
        $this->error('BINANCE_DEPOSIT_FALLBACK is not enabled in this runtime.');

        return self::FAILURE;
    }

    if (blank($fallback['api_key'] ?? null) || blank($fallback['api_secret'] ?? null)) {
        $this->error('Binance API key or secret is missing in this runtime.');

        return self::FAILURE;
    }

    $inspection = app(PaymentService::class)->inspectDirectBinancePayment($probe);

    if ($inspection === null) {
        $this->error('Binance deposit-history request failed. Check the Railway application logs for the provider error.');

        return self::FAILURE;
    }

    $transfer = $inspection['transfer'] ?? null;
    $diagnostics = $inspection['binance_diagnostics'] ?? [];

    $this->info('Read-only Binance deposit diagnosis complete. No order data was changed.');
    $this->line('Order: '.$order->order_id);
    $this->line('Order status: '.$order->status);
    $this->line('Diagnosis: '.($diagnostics['status'] ?? ($transfer ? 'matched' : 'no_matching_deposit')));
    $this->line('Records returned: '.($diagnostics['returned_records'] ?? 0));

    if ($transfer) {
        $this->newLine();
        $this->info('Matching deposit found.');
        $this->line('Reference: '.($transfer['tx_hash'] ?? '-'));
        $this->line('Amount: '.($transfer['amount'] ?? '-'));
        $this->line('Network: '.($transfer['network'] ?? '-'));
        $this->line('Confirmed at: '.($transfer['confirmed_at']?->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') ?? '-'));

        return self::SUCCESS;
    }

    if (! empty($diagnostics['http_status']) || ! empty($diagnostics['code'])) {
        $this->warn('API response: HTTP '.($diagnostics['http_status'] ?? '-').', code '.($diagnostics['code'] ?? '-'));
    }

    if (! empty($diagnostics['message'])) {
        $this->warn('API message: '.$diagnostics['message']);
    }

    if (! empty($diagnostics['rejections'])) {
        $this->warn('Rejected records: '.collect($diagnostics['rejections'])
            ->map(fn ($count, $reason) => $reason.'='.$count)
            ->implode(', '));
    }

    if (is_array($diagnostics['closest_record'] ?? null)) {
        $closest = $diagnostics['closest_record'];
        $this->line('Closest record: '.implode(' | ', [
            ($closest['amount'] ?? '-').' '.($closest['coin'] ?? '-'),
            $closest['network'] ?? '-',
            'address ...'.($closest['address_suffix'] ?? '-'),
            $closest['reference'] ?? '-',
        ]));
    }

    return self::FAILURE;
})->purpose('Read-only diagnosis of an existing order against Binance deposit history');

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
    $this->line("Other/legacy: {$summary['other']}");
    $this->line("GoPay QRIS: {$summary['gopay_qris']}");
    $this->line("Crypto: {$summary['crypto']}");
    $this->line("Binance Pay: {$summary['binance_pay']}");

    return self::SUCCESS;
})->purpose('Cancel expired pending orders and release their reserved license stocks');

Schedule::command('orders:expire-pending --limit=500')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('orders:scan-crypto --limit=100')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('orders:scan-binance-pay --limit=100')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('orders:release-expired-reservations')
    ->everyMinute()
    ->withoutOverlapping();
