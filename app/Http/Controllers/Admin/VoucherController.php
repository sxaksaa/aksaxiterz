<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
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
        $availableProducts = Product::query()
            ->orderBy('name')
            ->get();
        $productsById = $availableProducts->keyBy('id');

        $stats = [
            'total' => Voucher::count(),
            'available' => Voucher::where('is_active', true)
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->count(),
            'paid_uses' => Order::whereNotNull('voucher_id')->where('status', 'paid')->count(),
        ];

        return view('admin.vouchers.index', compact('vouchers', 'editVoucher', 'stats', 'availableProducts', 'productsById'));
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

    public function show(Voucher $voucher)
    {
        $voucher->loadCount([
            'orders as active_uses_count' => fn ($query) => $query->where('status', '!=', 'cancelled'),
            'orders as paid_uses_count' => fn ($query) => $query->where('status', 'paid'),
        ]);

        $summaryRows = $voucher->orders()
            ->with(['items', 'product', 'package'])
            ->oldest()
            ->get()
            ->map(fn (Order $order) => $this->usageRow($order));
        $paidRows = $summaryRows->filter(fn (array $row) => $row['order']->status === 'paid');
        $usageStats = [
            'total_orders' => $summaryRows->count(),
            'active_orders' => $summaryRows->filter(fn (array $row) => $row['order']->status !== 'cancelled')->count(),
            'paid_orders' => $paidRows->count(),
            'checkout_idr' => (int) $paidRows->where('currency_group', 'idr')->sum('subtotal_value'),
            'final_idr' => (int) $paidRows->where('currency_group', 'idr')->sum('final_value'),
            'discount_idr' => (int) $paidRows->where('currency_group', 'idr')->sum('discount_value'),
            'checkout_crypto' => round((float) $paidRows->where('currency_group', 'crypto')->sum('subtotal_value'), 6),
            'final_crypto' => round((float) $paidRows->where('currency_group', 'crypto')->sum('final_value'), 6),
            'discount_crypto' => round((float) $paidRows->where('currency_group', 'crypto')->sum('discount_value'), 6),
        ];

        $usageRows = $voucher->orders()
            ->with(['user', 'items', 'product', 'package'])
            ->latest()
            ->paginate(15);
        $usageRows->setCollection(
            $usageRows->getCollection()->map(fn (Order $order) => $this->usageRow($order))
        );

        return view('admin.vouchers.show', compact('voucher', 'usageRows', 'usageStats'));
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
            'required_product_ids' => ['nullable', 'array', 'max:10'],
            'required_product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
            'is_active' => ['required', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => array_values(array_filter([
                'nullable',
                'date',
                $request->filled('starts_at') ? 'after:starts_at' : null,
            ])),
        ]);

        $validated['is_active'] = $request->boolean('is_active');
        $validated['required_product_ids'] = collect($validated['required_product_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all() ?: null;

        return $validated;
    }

    private function usageRow(Order $order): array
    {
        $payload = is_array($order->payment_payload) ? $order->payment_payload : [];
        $items = $order->lineItems();
        $subtotalIdr = (int) $items->sum(fn ($item) => (int) $item->line_total_idr);
        $subtotalCrypto = round((float) $items->sum(fn ($item) => (float) $item->line_total_usdt), 6);
        $isCrypto = in_array($order->payment_method, ['crypto', 'binance_pay'], true);
        $methodLabel = match ($order->payment_method) {
            'binance_pay' => 'Binance Pay',
            'crypto' => 'Crypto',
            'pakasir' => 'QRIS',
            default => ucfirst($order->payment_method ?: 'Legacy'),
        };
        $itemLabels = $items
            ->take(3)
            ->map(fn ($item) => trim($item->product_name.' - '.$item->package_name).' x'.max(1, (int) $item->quantity))
            ->values();

        if ($items->count() > 3) {
            $itemLabels->push('+'.($items->count() - 3).' more');
        }

        if ($isCrypto) {
            $token = strtoupper((string) ($payload['token'] ?? 'USDT'));
            $finalValue = round((float) ($payload['base_amount'] ?? $order->price), 6);
            $paidValue = round((float) ($payload['amount'] ?? $order->price), 6);

            return [
                'order' => $order,
                'method_label' => $methodLabel,
                'currency_group' => 'crypto',
                'currency' => $token,
                'subtotal_value' => $subtotalCrypto,
                'final_value' => $finalValue,
                'paid_value' => $paidValue,
                'discount_value' => max(0, round($subtotalCrypto - $finalValue, 6)),
                'item_summary' => $itemLabels->implode(', '),
            ];
        }

        $finalValue = (int) round((float) $order->price);

        return [
            'order' => $order,
            'method_label' => $methodLabel,
            'currency_group' => 'idr',
            'currency' => 'IDR',
            'subtotal_value' => $subtotalIdr,
            'final_value' => $finalValue,
            'paid_value' => (int) ($payload['total_payment'] ?? $payload['amount'] ?? $finalValue),
            'discount_value' => max(0, $subtotalIdr - $finalValue),
            'item_summary' => $itemLabels->implode(', '),
        ];
    }
}
