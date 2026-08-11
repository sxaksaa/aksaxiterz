@extends('layouts.app')

@section('content')
    <div class="page-shell py-6 md:py-10">
        <section class="orders-hero fade-up mb-6">
            <div>
                <div>
                    <p class="mb-2 text-sm font-semibold text-aksa-accent">Admin</p>
                    <h1 class="text-3xl font-bold tracking-normal md:text-4xl">Users</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                        See who has registered, how many orders they made, and how many licenses they received.
                    </p>
                </div>
            </div>

            <div class="admin-stat-grid mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['total'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Registered users</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['buyers'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Users with licenses</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['orders'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Total orders</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['licenses'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Delivered licenses</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['admins'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Admins</div>
                </div>
            </div>
        </section>

        <section class="product-section mb-6 fade-up">
            <form id="userSearchForm" method="GET" action="{{ route('admin.users.index') }}" class="grid gap-3 md:grid-cols-[1fr_auto] md:items-end">
                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Search</span>
                    <input name="search" value="{{ request('search') }}" class="search-bar w-full"
                        placeholder="Name or email">
                </label>

                <div class="flex gap-2">
                    <button type="submit" class="btn-footer h-12">
                        <x-ui.icon name="filter" class="h-4 w-4" />
                        <span>Filter</span>
                    </button>
                    <a href="{{ route('admin.users.index') }}" class="btn-footer-secondary h-12">
                        <x-ui.icon name="rotate-ccw" class="h-4 w-4" />
                        <span>Reset</span>
                    </a>
                </div>
            </form>
        </section>

        <div class="orders-table-wrap hidden lg:block">
            <div class="flex items-center justify-between gap-3 border-b border-[#27272A] px-4 py-4">
                <div>
                    <h2 class="text-sm font-semibold text-white">Registered Users</h2>
                    <p class="mt-1 text-xs text-gray-500">Users are created from Google login or normal auth records.</p>
                </div>
                <span class="rounded-lg border border-aksa-accent-30 bg-aksa-accent-10 px-3 py-1 text-xs font-semibold text-aksa-accent">
                    {{ $users->total() }} users
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[1120px] text-sm">
                    <thead class="bg-[#111115] text-xs uppercase tracking-normal text-gray-500">
                        <tr>
                            <th class="p-4 text-left">User</th>
                            <th class="p-4 text-left">Email</th>
                            <th class="p-4 text-left">Role</th>
                            <th class="p-4 text-left">Orders</th>
                            <th class="p-4 text-left">Last Order</th>
                            <th class="p-4 text-left">Licenses</th>
                            <th class="p-4 text-left">Last License</th>
                            <th class="p-4 text-left">Joined</th>
                            <th class="p-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($users as $user)
                            @php
                                $latestOrder = $user->latestOrder;
                                $latestLicense = $user->latestLicense;
                            @endphp
                            <tr class="orders-table-row">
                                <td class="p-4">
                                    <div class="flex items-center gap-3">
                                        @if ($user->avatar)
                                            <img src="{{ $user->avatar }}" alt="" class="h-9 w-9 rounded-full object-cover">
                                        @else
                                            <div class="flex h-9 w-9 items-center justify-center rounded-full border border-aksa-accent-35 bg-aksa-accent-10 text-xs font-semibold text-aksa-accent-soft">
                                                {{ strtoupper(substr($user->name ?: $user->email, 0, 1)) }}
                                            </div>
                                        @endif
                                        <div class="min-w-0">
                                            <div class="max-w-[220px] truncate font-semibold text-white">{{ $user->name }}</div>
                                            <div class="text-xs text-gray-500">ID {{ $user->id }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-4 text-gray-300">{{ $user->email }}</td>
                                <td class="p-4">
                                    <span class="status-pill {{ $user->isAdmin() ? 'status-pill-paid' : 'status-pill-pending' }}">
                                        {{ $user->isAdmin() ? 'Admin' : 'Customer' }}
                                    </span>
                                </td>
                                <td class="p-4">
                                    <div class="font-semibold text-white">{{ $user->orders_count }} total</div>
                                    <div class="mt-1 text-xs text-gray-500">{{ $user->paid_orders_count }} paid</div>
                                </td>
                                <td class="p-4">
                                    @if ($latestOrder)
                                        <div class="font-mono text-xs text-gray-300">{{ $latestOrder->order_id }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ ucfirst($latestOrder->status) }}</div>
                                    @else
                                        <span class="text-xs text-gray-500">No orders</span>
                                    @endif
                                </td>
                                <td class="p-4">
                                    <div class="font-semibold text-white">{{ $user->licenses_count }}</div>
                                    <div class="mt-1 text-xs text-gray-500">delivered</div>
                                </td>
                                <td class="p-4">
                                    @if ($latestLicense)
                                        <div class="max-w-[200px] truncate font-semibold text-white">{{ $latestLicense->product->name ?? 'Product' }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ $latestLicense->orderItem?->package_name ?? $latestLicense->duration ?? '-' }}</div>
                                    @else
                                        <span class="text-xs text-gray-500">No licenses</span>
                                    @endif
                                </td>
                                <td class="p-4 text-xs text-gray-400">{{ $user->created_at?->format('d M Y, H:i') ?? '-' }}</td>
                                <td class="p-4 text-right">
                                    <a href="{{ route('admin.users.show', $user) }}" class="order-action">
                                        <x-ui.icon name="eye" class="h-4 w-4" />
                                        <span>Detail</span>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="p-8">
                                    <div class="empty-state">No users found</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-4 lg:hidden">
            @forelse ($users as $user)
                <article class="order-mobile-card motion-card">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-semibold text-white">{{ $user->name }}</div>
                            <div class="mt-1 truncate text-xs text-gray-500">{{ $user->email }}</div>
                        </div>
                        <span class="status-pill {{ $user->isAdmin() ? 'status-pill-paid' : 'status-pill-pending' }}">
                            {{ $user->isAdmin() ? 'Admin' : 'Customer' }}
                        </span>
                    </div>

                    <div class="mt-4 grid gap-2 text-sm text-gray-400">
                        <div>Orders: <span class="font-semibold text-white">{{ $user->orders_count }}</span> · {{ $user->paid_orders_count }} paid</div>
                        <div>Licenses: <span class="font-semibold text-white">{{ $user->licenses_count }}</span></div>
                        <div>Latest order: <span class="font-semibold text-white">{{ $user->latestOrder?->order_id ?? '-' }}</span></div>
                        <div>Latest license: <span class="font-semibold text-white">{{ $user->latestLicense?->product?->name ?? '-' }}</span></div>
                        <div>Joined: {{ $user->created_at?->format('d M Y, H:i') ?? '-' }}</div>
                    </div>

                    <a href="{{ route('admin.users.show', $user) }}" class="order-action mt-4 w-full">
                        <x-ui.icon name="eye" class="h-4 w-4" />
                        <span>Detail</span>
                    </a>
                </article>
            @empty
                <div class="empty-state">No users found</div>
            @endforelse
        </div>

        @include('partials.pagination', [
            'paginator' => $users,
            'label' => 'User pagination',
            'itemLabel' => 'users',
        ])
    </div>

@endsection
