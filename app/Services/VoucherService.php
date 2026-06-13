<?php

namespace App\Services;

use App\Exceptions\VoucherException;
use App\Models\Package;
use App\Models\User;
use App\Models\Voucher;

class VoucherService
{
    public function quote(
        Package $package,
        User $user,
        ?string $code = null,
        ?int $voucherId = null,
        ?int $excludeOrderId = null,
        bool $lock = false,
        string $paymentMethod = 'pakasir',
        ?string $coin = null
    ): array {
        $baseIdr = max(0, (int) $package->price);
        $baseUsdt = max(0, (float) ($package->price_usdt ?? 0));
        $paymentMethod = strtolower($paymentMethod);

        if (! in_array($paymentMethod, ['pakasir', 'crypto'], true)) {
            throw new VoucherException('Unsupported voucher payment method.');
        }

        $token = $paymentMethod === 'crypto' ? $this->cryptoToken($coin) : null;

        if (blank($code) && ! $voucherId) {
            return $this->emptyQuote($baseIdr, $baseUsdt, $paymentMethod, $token);
        }

        $query = Voucher::query();

        if ($lock) {
            $query->lockForUpdate();
        }

        $voucher = $voucherId
            ? $query->find($voucherId)
            : $query->where('code', $this->normalizeCode((string) $code))->first();

        if (! $voucher) {
            throw new VoucherException('Voucher code was not found.');
        }

        $this->ensureAvailable($voucher, $user, $baseIdr, $excludeOrderId);

        $discountIdr = min(
            intdiv($baseIdr * $voucher->discount_percent, 100),
            $voucher->max_discount
        );

        $maxDiscountCrypto = $token ? $this->cryptoMaxDiscount($voucher, $token) : 0;
        $discountUsdt = $token
            ? min(round($baseUsdt * ($voucher->discount_percent / 100), 6), $maxDiscountCrypto)
            : 0;

        $selectedDiscount = $paymentMethod === 'crypto' ? $discountUsdt : $discountIdr;

        if ($selectedDiscount <= 0) {
            throw new VoucherException('This voucher does not discount the selected package.');
        }

        return [
            'voucher_id' => $voucher->id,
            'code' => $voucher->code,
            'payment_method' => $paymentMethod,
            'token' => $token,
            'discount_percent' => $voucher->discount_percent,
            'max_discount' => $voucher->max_discount,
            'max_discount_crypto' => $maxDiscountCrypto,
            'minimum_purchase' => $voucher->minimum_purchase,
            'base_idr' => $baseIdr,
            'discount_idr' => $discountIdr,
            'final_idr' => max(1, $baseIdr - $discountIdr),
            'base_usdt' => $baseUsdt,
            'discount_usdt' => $discountUsdt,
            'final_usdt' => max(0.000001, round($baseUsdt - $discountUsdt, 6)),
            'starts_at' => $voucher->starts_at?->toIso8601String(),
            'expires_at' => $voucher->expires_at?->toIso8601String(),
        ];
    }

    public function checkoutPrice(array $quote, string $paymentMethod): float|int
    {
        return $paymentMethod === 'crypto'
            ? (float) $quote['final_usdt']
            : (int) $quote['final_idr'];
    }

    public function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    private function ensureAvailable(Voucher $voucher, User $user, int $baseIdr, ?int $excludeOrderId): void
    {
        if (! $voucher->is_active) {
            throw new VoucherException('This voucher is inactive.');
        }

        if ($voucher->starts_at && $voucher->starts_at->isFuture()) {
            throw new VoucherException('This voucher is not active yet.');
        }

        if ($voucher->expires_at && ! $voucher->expires_at->isFuture()) {
            throw new VoucherException('This voucher has expired.');
        }

        if ($baseIdr < $voucher->minimum_purchase) {
            throw new VoucherException(
                'Minimum purchase for this voucher is Rp '.number_format($voucher->minimum_purchase, 0, ',', '.').'.'
            );
        }

        $activeOrders = $voucher->orders()
            ->where('status', '!=', 'cancelled')
            ->when($excludeOrderId, fn ($query) => $query->whereKeyNot($excludeOrderId));

        if ($voucher->usage_limit !== null && (clone $activeOrders)->count() >= $voucher->usage_limit) {
            throw new VoucherException('This voucher has reached its usage limit.');
        }

        if (
            $voucher->per_user_limit > 0 &&
            (clone $activeOrders)->where('user_id', $user->id)->count() >= $voucher->per_user_limit
        ) {
            throw new VoucherException('You have already used this voucher.');
        }
    }

    private function cryptoToken(?string $coin): string
    {
        $coin = strtolower(trim((string) $coin));

        if (str_starts_with($coin, 'usdt')) {
            return 'USDT';
        }

        if (str_starts_with($coin, 'usdc')) {
            return 'USDC';
        }

        throw new VoucherException('Select a supported crypto coin and network first.');
    }

    private function cryptoMaxDiscount(Voucher $voucher, string $token): float
    {
        return max(0, (float) ($token === 'USDC'
            ? $voucher->max_discount_usdc
            : $voucher->max_discount_usdt));
    }

    private function emptyQuote(int $baseIdr, float $baseUsdt, string $paymentMethod, ?string $token): array
    {
        return [
            'voucher_id' => null,
            'code' => null,
            'payment_method' => $paymentMethod,
            'token' => $token,
            'discount_percent' => 0,
            'max_discount' => 0,
            'max_discount_crypto' => 0,
            'minimum_purchase' => 0,
            'base_idr' => $baseIdr,
            'discount_idr' => 0,
            'final_idr' => $baseIdr,
            'base_usdt' => $baseUsdt,
            'discount_usdt' => 0,
            'final_usdt' => $baseUsdt,
            'starts_at' => null,
            'expires_at' => null,
        ];
    }
}
