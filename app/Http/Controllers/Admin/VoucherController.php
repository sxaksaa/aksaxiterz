<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VoucherController extends Controller
{
    public function index(Request $request)
    {
        $vouchers = Voucher::query()
            ->withCount([
                'orders as active_uses_count' => fn ($query) => $query->where('status', '!=', 'cancelled'),
                'orders as paid_uses_count' => fn ($query) => $query->where('status', 'paid'),
            ])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $query->where('code', 'like', '%'.$request->string('search')->toString().'%');
            })
            ->latest()
            ->paginate(12)
            ->withQueryString();

        $editVoucher = $request->filled('edit')
            ? Voucher::find($request->integer('edit'))
            : null;

        $stats = [
            'total' => Voucher::count(),
            'available' => Voucher::where('is_active', true)
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->count(),
            'paid_uses' => Order::whereNotNull('voucher_id')->where('status', 'paid')->count(),
        ];

        return view('admin.vouchers.index', compact('vouchers', 'editVoucher', 'stats'));
    }

    public function store(Request $request)
    {
        Voucher::create($this->validatedVoucher($request));

        return redirect()
            ->route('admin.vouchers.index')
            ->with('info', 'Voucher created.');
    }

    public function update(Request $request, Voucher $voucher)
    {
        $voucher->update($this->validatedVoucher($request, $voucher));

        return redirect()
            ->route('admin.vouchers.index')
            ->with('info', 'Voucher updated.');
    }

    public function destroy(Voucher $voucher)
    {
        if ($voucher->orders()->exists()) {
            return back()->withErrors([
                'voucher' => 'Vouchers with order history cannot be deleted. Deactivate it instead.',
            ]);
        }

        $voucher->delete();

        return redirect()
            ->route('admin.vouchers.index')
            ->with('info', 'Voucher deleted.');
    }

    private function validatedVoucher(Request $request, ?Voucher $voucher = null): array
    {
        if ($request->filled('code')) {
            $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        }

        if (! $request->filled('per_user_limit')) {
            $request->merge(['per_user_limit' => 0]);
        }

        foreach (['usage_limit', 'starts_at', 'expires_at'] as $optionalField) {
            if (! $request->filled($optionalField)) {
                $request->merge([$optionalField => null]);
            }
        }

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('vouchers', 'code')->ignore($voucher?->id),
            ],
            'discount_percent' => ['required', 'integer', 'min:1', 'max:99'],
            'max_discount' => ['required', 'integer', 'min:1', 'max:999999999'],
            'max_discount_usdt' => ['required', 'numeric', 'min:0.000001', 'max:999999.999999'],
            'max_discount_usdc' => ['required', 'numeric', 'min:0.000001', 'max:999999.999999'],
            'minimum_purchase' => ['required', 'integer', 'min:0', 'max:999999999'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:999999999'],
            'per_user_limit' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['required', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => array_values(array_filter([
                'nullable',
                'date',
                $request->filled('starts_at') ? 'after:starts_at' : null,
            ])),
        ]);

        $validated['is_active'] = $request->boolean('is_active');

        return $validated;
    }
}
