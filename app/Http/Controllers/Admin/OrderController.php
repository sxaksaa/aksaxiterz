<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Models\Order;
use App\Services\OrderFulfillmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(private readonly OrderFulfillmentService $orderFulfillmentService)
    {
    }

    public function index(Request $request)
    {
        $orders = Order::with(['user', 'product', 'package', 'license'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->search;

                $query->where(function ($query) use ($search) {
                    $query->where('order_id', 'like', '%'.$search.'%')
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', '%'.$search.'%')
                                ->orWhere('email', 'like', '%'.$search.'%');
                        })
                        ->orWhereHas('product', fn ($productQuery) => $productQuery->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->status))
            ->when($request->filled('method'), fn ($query) => $query->where('payment_method', $request->method))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $stats = [
            'total' => Order::count(),
            'pending' => Order::where('status', 'pending')->count(),
            'paid' => Order::where('status', 'paid')->count(),
            'cancelled' => Order::where('status', 'cancelled')->count(),
            'licenses' => License::count(),
        ];

        return view('admin.orders.index', compact('orders', 'stats'));
    }

    public function show(Order $order)
    {
        $order->load(['user', 'product', 'package', 'license']);

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
            ->with('info', 'Order marked paid and license is visible to the customer.');
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
}
