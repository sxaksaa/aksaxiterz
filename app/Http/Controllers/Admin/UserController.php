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
            ->withCount(['orders', 'licenses'])
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
}
