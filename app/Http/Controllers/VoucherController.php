<?php

namespace App\Http\Controllers;

use App\Exceptions\VoucherException;
use App\Models\Package;
use App\Services\VoucherService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VoucherController extends Controller
{
    public function preview(Request $request, VoucherService $voucherService)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'payment_method' => ['required', Rule::in(['pakasir', 'crypto', 'binance_pay'])],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'coin' => [
                'nullable',
                'string',
                'max:20',
                'required_if:payment_method,crypto',
                Rule::in(array_merge(['usdt', 'usdc'], array_keys(config('services.crypto_direct.networks', [])))),
            ],
        ]);

        $package = Package::findOrFail($validated['package_id']);
        $quantity = (int) ($validated['quantity'] ?? 1);

        if ($quantity > $package->availableLicenseStocks()->count()) {
            return response()->json(['message' => 'The selected quantity is no longer available.'], 422);
        }

        try {
            $quote = $voucherService->quote(
                $package,
                $request->user(),
                $validated['code'],
                null,
                null,
                false,
                $validated['payment_method'],
                $validated['coin'] ?? null,
                $quantity
            );
        } catch (VoucherException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }

        unset($quote['voucher_id']);

        return response()->json($quote);
    }
}
