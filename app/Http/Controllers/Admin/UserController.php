<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::query()
            ->with([
                'latestOrder.items',
                'latestLicense.product',
                'latestLicense.orderItem',
            ])
            ->withCount([
                'orders',
                'licenses',
                'orders as paid_orders_count' => fn ($query) => $query->where('status', 'paid'),
            ])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->search;

                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $adminEmails = config('admin.emails', []);

        $stats = [
            'total' => User::count(),
            'buyers' => User::has('licenses')->count(),
            'orders' => Order::count(),
            'licenses' => License::count(),
            'admins' => $adminEmails ? User::whereIn('email', $adminEmails)->count() : 0,
        ];

        return view('admin.users.index', compact('users', 'stats'));
    }

    public function show(User $user)
    {
        $user->loadCount([
            'orders',
            'licenses',
            'orders as paid_orders_count' => fn ($query) => $query->where('status', 'paid'),
            'orders as pending_orders_count' => fn ($query) => $query->where('status', 'pending'),
            'orders as cancelled_orders_count' => fn ($query) => $query->where('status', 'cancelled'),
        ]);

        $paidOrders = $user->orders()
            ->where('status', 'paid')
            ->with('items')
            ->get();
        $spendStats = $this->spendStats($paidOrders);
        $orders = $user->orders()
            ->with(['items.product', 'items.package', 'voucher'])
            ->withCount('licenses')
            ->latest()
            ->paginate(10, ['*'], 'orders_page')
            ->withQueryString();
        $licenses = $user->licenses()
            ->with(['product', 'orderItem', 'order'])
            ->latest()
            ->paginate(10, ['*'], 'licenses_page')
            ->withQueryString();

        return view('admin.users.show', compact('user', 'spendStats', 'orders', 'licenses'));
    }

    private function spendStats($orders): array
    {
        $stats = [
            'idr' => 0,
            'crypto' => 0.0,
        ];

        foreach ($orders as $order) {
            $payload = is_array($order->payment_payload) ? $order->payment_payload : [];

            if (in_array($order->payment_method, ['crypto', 'binance_pay'], true)) {
                $stats['crypto'] += (float) ($payload['base_amount'] ?? $order->price);

                continue;
            }

            $stats['idr'] += (int) round((float) $order->price);
        }

        $stats['crypto'] = round($stats['crypto'], 6);

        return $stats;
    }
}
