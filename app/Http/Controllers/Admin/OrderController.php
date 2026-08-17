<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Models\Order;
use App\Services\OrderFulfillmentService;
use App\Services\PendingOrderExpirationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderFulfillmentService $orderFulfillmentService,
        private readonly PendingOrderExpirationService $pendingOrderExpirationService
    ) {}

    public function index(Request $request)
    {
        $this->pendingOrderExpirationService->expire(limit: 500);

        $orders = Order::with(['user', 'product', 'package', 'items'])
            ->withCount('licenses')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->search);
                $amount = $this->searchAmount($search);

                $query->where(function ($query) use ($search, $amount) {
                    $query->where('order_id', 'like', '%'.$search.'%')
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', '%'.$search.'%')
                                ->orWhere('email', 'like', '%'.$search.'%');
                        })
                        ->orWhereHas('product', fn ($productQuery) => $productQuery->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('items', fn ($itemQuery) => $itemQuery->where('product_name', 'like', '%'.$search.'%'));

                    if ($amount !== null) {
                        $query->orWhere('price', $amount);
                    }
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->status))
            ->when($request->filled('method'), fn ($query) => $query->where('payment_method', $request->method))
            ->when($request->delivery === 'incomplete', fn ($query) => $this->deliveryIssues($query))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $stats = [
            'total' => Order::count(),
            'pending' => Order::where('status', 'pending')->count(),
            'paid' => Order::where('status', 'paid')->count(),
            'cancelled' => Order::where('status', 'cancelled')->count(),
            'delivery_issues' => $this->deliveryIssues(Order::query())->count(),
            'licenses' => License::count(),
        ];

        return view('admin.orders.index', compact('orders', 'stats'));
    }

    public function show(Order $order)
    {
        $this->pendingOrderExpirationService->expire($order->user_id);
        $order->refresh();
        $order->load(['user', 'product', 'package', 'items', 'licenses.orderItem']);

        return view('admin.orders.show', compact('order'));
    }

    public function markPaid(Order $order)
    {
        try {
            DB::transaction(function () use ($order): void {
                $lockedOrder = Order::whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->orderFulfillmentService->fulfill($lockedOrder);
            });
        } catch (\Exception $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.orders.show', $order)
            ->with('info', 'Order marked paid and all available licenses are visible to the customer.');
    }

    public function resyncLicense(Order $order)
    {
        try {
            DB::transaction(function () use ($order): void {
                $lockedOrder = Order::whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->orderFulfillmentService->fulfill($lockedOrder);
            });
        } catch (\Exception $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.orders.show', $order)
            ->with('info', 'License delivery was resynced for this order.');
    }

    private function deliveryIssues($query)
    {
        return $query
            ->where('status', 'paid')
            ->whereRaw(
                '(SELECT COUNT(*) FROM licenses WHERE licenses.order_id = orders.order_id) < COALESCE(orders.quantity, 1)'
            );
    }

    private function searchAmount(string $search): ?string
    {
        $amount = preg_replace('/\s+/u', '', strtolower($search));
        $amount = preg_replace('/^(rp|idr)/', '', $amount);
        $amount = preg_replace('/(usdt|usdc)$/', '', $amount);

        if ($amount === null || $amount === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $amount) === 1) {
            return $amount;
        }

        if (preg_match('/^\d{1,3}(?:[.,]\d{3})+$/', $amount) === 1) {
            return str_replace([',', '.'], '', $amount);
        }

        if (preg_match('/^\d{1,3}(?:\.\d{3})+,\d{1,6}$/', $amount) === 1) {
            return str_replace(',', '.', str_replace('.', '', $amount));
        }

        if (preg_match('/^\d{1,3}(?:,\d{3})+\.\d{1,6}$/', $amount) === 1) {
            return str_replace(',', '', $amount);
        }

        if (preg_match('/^\d+[.,]\d{1,6}$/', $amount) === 1) {
            return str_replace(',', '.', $amount);
        }

        return null;
    }
}
