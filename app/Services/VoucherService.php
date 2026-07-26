<?php

namespace App\Services;

use App\Exceptions\VoucherException;
use App\Models\Package;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Support\Collection;

class VoucherService
{
    public function quote(
        Package $package,
        User $user,
        ?string $code = null,
        ?int $voucherId = null,
        ?int $excludeOrderId = null,
        bool $lock = false,
        string $paymentMethod = 'gopay_qris',
        ?string $coin = null,
        int $quantity = 1
    ): array {
        $quantity = max(1, $quantity);
        $unitIdr = max(0, (int) $package->price);
        $unitUsdt = max(0, (float) ($package->price_usdt ?? 0));
        $baseIdr = $unitIdr * $quantity;
        $baseUsdt = round($unitUsdt * $quantity, 6);
        $discountLines = collect([[
            'product_id' => $package->product_id,
            'package_id' => $package->id,
            'unit_idr' => $unitIdr,
            'unit_usdt' => $unitUsdt,
            'quantity' => $quantity,
        ]]);

        return $this->quoteTotals(
            $baseIdr,
            $baseUsdt,
            $user,
            $code,
            $voucherId,
            $excludeOrderId,
            $lock,
            $paymentMethod,
            $coin,
            $quantity,
            $discountLines
        );
    }

    public function quoteCart(
        Collection $items,
        User $user,
        ?string $code = null,
        ?int $voucherId = null,
        ?int $excludeOrderId = null,
        bool $lock = false,
        string $paymentMethod = 'gopay_qris',
        ?string $coin = null
    ): array {
        $baseIdr = (int) $items->sum(fn ($item) => (
            max(0, (int) ($item->unit_price_idr ?? $item->package?->price ?? 0)) *
            max(1, (int) $item->quantity)
        ));
        $baseUsdt = round((float) $items->sum(fn ($item) => (
            max(0, (float) ($item->unit_price_usdt ?? $item->package?->price_usdt ?? 0)) *
            max(1, (int) $item->quantity)
        )), 6);
        $quantity = max(1, (int) $items->sum(fn ($item) => max(1, (int) $item->quantity)));
        $discountLines = $items->map(fn ($item) => [
            'product_id' => (int) $item->product_id,
            'package_id' => (int) $item->package_id,
            'unit_idr' => max(0, (int) ($item->unit_price_idr ?? $item->package?->price ?? 0)),
            'unit_usdt' => max(0, (float) ($item->unit_price_usdt ?? $item->package?->price_usdt ?? 0)),
            'quantity' => max(1, (int) $item->quantity),
        ])->values();

        return $this->quoteTotals(
            $baseIdr,
            $baseUsdt,
            $user,
            $code,
            $voucherId,
            $excludeOrderId,
            $lock,
            $paymentMethod,
            $coin,
            $quantity,
            $discountLines
        );
    }

    private function quoteTotals(
        int $baseIdr,
        float $baseUsdt,
        User $user,
        ?string $code,
        ?int $voucherId,
        ?int $excludeOrderId,
        bool $lock,
        string $paymentMethod,
        ?string $coin,
        int $quantity,
        Collection $discountLines
    ): array {
        $paymentMethod = strtolower($paymentMethod);

        if (! in_array($paymentMethod, ['gopay_qris', 'crypto', 'binance_pay'], true)) {
            throw new VoucherException('Unsupported voucher payment method.');
        }

        $usesStablecoin = in_array($paymentMethod, ['crypto', 'binance_pay'], true);
        $token = $usesStablecoin ? $this->cryptoToken($coin) : null;

        if (blank($code) && ! $voucherId) {
            return $this->emptyQuote($baseIdr, $baseUsdt, $paymentMethod, $token, $quantity);
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
        $eligibleDiscountLines = $this->eligibleDiscountLines($voucher, $discountLines);
        $discountUnits = max(1, (int) $eligibleDiscountLines->sum(fn (array $line) => max(1, (int) $line['quantity'])));

        $maxDiscountCrypto = $token ? $this->cryptoMaxDiscount($voucher, $token) : 0;
        $discountIdr = (int) $eligibleDiscountLines->sum(fn (array $line) => (
            min(
                intdiv($line['unit_idr'] * $voucher->discount_percent, 100),
                $voucher->max_discount
            ) * $line['quantity']
        ));
        $discountUsdt = $token
            ? round((float) $eligibleDiscountLines->sum(fn (array $line) => (
                min(
                    round($line['unit_usdt'] * ($voucher->discount_percent / 100), 6),
                    $maxDiscountCrypto
                ) * $line['quantity']
            )), 6)
            : 0;

        $selectedDiscount = $usesStablecoin ? $discountUsdt : $discountIdr;

        if ($selectedDiscount <= 0) {
            throw new VoucherException('This voucher does not discount the selected package.');
        }

        return [
            'voucher_id' => $voucher->id,
            'code' => $voucher->code,
            'payment_method' => $paymentMethod,
            'token' => $token,
            'quantity' => $quantity,
            'discount_percent' => $voucher->discount_percent,
            'max_discount' => $voucher->max_discount,
            'max_discount_crypto' => $maxDiscountCrypto,
            'discount_cap_scope' => 'per_item',
            'discount_units' => $discountUnits,
            'max_discount_total' => $voucher->max_discount * $discountUnits,
            'max_discount_crypto_total' => round($maxDiscountCrypto * $discountUnits, 6),
            'required_product_ids' => $voucher->requiredProductIds(),
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
        return in_array($paymentMethod, ['crypto', 'binance_pay'], true)
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

    private function eligibleDiscountLines(Voucher $voucher, Collection $discountLines): Collection
    {
        $requiredProductIds = $voucher->requiredProductIds();

        if ($requiredProductIds === []) {
            return $discountLines;
        }

        $cartProductIds = $discountLines
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();
        $missingProductIds = collect($requiredProductIds)->diff($cartProductIds);

        if ($missingProductIds->isNotEmpty()) {
            throw new VoucherException('This voucher requires all selected bundle products.');
        }

        return $discountLines
            ->filter(fn (array $line) => in_array((int) $line['product_id'], $requiredProductIds, true))
            ->values();
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

    private function emptyQuote(int $baseIdr, float $baseUsdt, string $paymentMethod, ?string $token, int $quantity): array
    {
        return [
            'voucher_id' => null,
            'code' => null,
            'payment_method' => $paymentMethod,
            'token' => $token,
            'quantity' => $quantity,
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
