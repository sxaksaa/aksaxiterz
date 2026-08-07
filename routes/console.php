<?php

use App\Models\LicenseStock;
use App\Models\Order;
use App\Services\BinancePayOrderVerifier;
use App\Services\DirectCryptoOrderVerifier;
use App\Services\PaymentService;
use App\Services\PendingGopayDeliveryService;
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

Artisan::command('orders:retry-gopay-delivery {--limit=100 : Maximum paid QRIS orders to retry}', function () {
    $limit = max(1, (int) $this->option('limit'));
    $summary = app(PendingGopayDeliveryService::class)->retry($limit);

    $this->info('GoPay delivery retry complete.');
    $this->line("Checked: {$summary['checked']}");
    $this->line("Delivered: {$summary['delivered']}");
    $this->line("Waiting for stock: {$summary['waiting_for_stock']}");
    $this->line("Failed: {$summary['failed']}");

    return self::SUCCESS;
})->purpose('Deliver licenses for verified QRIS payments after stock becomes available');

Artisan::command('payments:verify-gopay-config', function () {
    if (! (bool) config('services.gopay_qris.enabled')) {
        $this->info('GoPay QRIS checkout is disabled.');

        return self::SUCCESS;
    }

    $recoveryHours = (int) config('services.gopay_qris.recovery_hours');
    $notificationMaxAgeHours = (int) config('services.gopay_qris.notification_max_age_hours');
    $delayedRecoveryMinutes = (int) config('services.gopay_qris.delayed_recovery_min_minutes');
    $amountQuarantineHours = (int) config('services.gopay_qris.amount_quarantine_hours');
    $errors = [];

    foreach ([
        'static_payload',
        'merchant_reference',
        'webhook_token',
        'webhook_secret',
    ] as $key) {
        if (blank(config("services.gopay_qris.{$key}"))) {
            $errors[] = "services.gopay_qris.{$key} is empty";
        }
    }

    $webhookToken = (string) config('services.gopay_qris.webhook_token');
    $webhookSecret = (string) config('services.gopay_qris.webhook_secret');

    if ($webhookToken !== '' && strlen($webhookToken) < 32) {
        $errors[] = 'webhook_token must contain at least 32 characters';
    }

    if ($webhookSecret !== '' && strlen($webhookSecret) < 32) {
        $errors[] = 'webhook_secret must contain at least 32 characters';
    }

    if ($webhookToken !== '' && $webhookSecret !== '' && hash_equals($webhookToken, $webhookSecret)) {
        $errors[] = 'webhook_token and webhook_secret must be different';
    }

    if (empty(config('services.gopay_qris.allowed_devices'))) {
        $errors[] = 'services.gopay_qris.allowed_devices is empty';
    }

    if ($recoveryHours < 72 || $recoveryHours > 168) {
        $errors[] = 'recovery_hours must be between 72 and 168';
    }

    if ($notificationMaxAgeHours < $recoveryHours || $notificationMaxAgeHours > 168) {
        $errors[] = 'notification_max_age_hours must cover recovery and cannot exceed 168';
    }

    if ($delayedRecoveryMinutes < 60 || $delayedRecoveryMinutes > 1440) {
        $errors[] = 'delayed_recovery_min_minutes must be between 60 and 1440';
    }

    if ($amountQuarantineHours < 168 || $amountQuarantineHours > 720) {
        $errors[] = 'amount_quarantine_hours must be between 168 and 720';
    }

    if ($errors !== []) {
        foreach ($errors as $error) {
            $this->error($error);
        }

        return self::FAILURE;
    }

    $this->info(sprintf(
        'GoPay QRIS config verified: recovery=%dh, max_age=%dh, delayed_min=%dm, amount_quarantine=%dh.',
        $recoveryHours,
        $notificationMaxAgeHours,
        $delayedRecoveryMinutes,
        $amountQuarantineHours
    ));

    return self::SUCCESS;
})->purpose('Validate non-secret GoPay QRIS reliability settings');

Schedule::command('orders:expire-pending --limit=500')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('orders:retry-gopay-delivery --limit=100')
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::command('orders:scan-crypto --limit=100')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('orders:scan-binance-pay --limit=100')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('orders:release-expired-reservations')
    ->everyMinute()
    ->withoutOverlapping();
