@extends('layouts.app')

@section('content')
    @php
        $packageLabel = function ($package) {
            $name = str_replace(['1 Hari', '7 Hari', '30 Hari', 'Hari'], ['1 Day', '7 Days', '30 Days', 'Days'], $package->name);

            return ($package->product->name ?? 'Product') . ' - ' . $name;
        };
    @endphp

    <div class="page-shell py-6 md:py-10">
        <section class="orders-hero fade-up mb-6">
            <div class="grid gap-5 lg:grid-cols-[1fr_auto] lg:items-end">
                <div>
                    <p class="mb-2 text-sm font-semibold text-[#C084FC]">Admin</p>
                    <h1 class="text-3xl font-bold tracking-normal md:text-4xl">License Stock</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                        Add, search, and maintain unsold license keys before they are delivered to customers.
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('admin.users.index') }}" class="btn-footer-secondary">Users</a>
                    <a href="/orders" class="btn-footer-secondary">Orders</a>
                    <a href="/licenses" class="btn-footer">Licenses</a>
                </div>
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['total'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Total keys</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['available'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Available</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['sold'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Sold</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['low_stock'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Low stock packages</div>
                </div>
            </div>
        </section>

        @if (session('info'))
            <div class="mb-4 rounded-xl border border-[#9333EA]/30 bg-[#9333EA]/10 px-4 py-3 text-sm text-[#D8B4FE]">
                {{ session('info') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                {{ $errors->first() }}
            </div>
        @endif

        @if ($lowStockPackages->isNotEmpty())
            <section class="mb-6 rounded-xl border border-amber-400/30 bg-amber-400/10 px-4 py-4 text-sm text-amber-100">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="font-semibold text-amber-50">Low stock notice</h2>
                        <p class="mt-1 text-xs text-amber-100/80">
                            Packages with {{ $lowStockThreshold }} or fewer available keys need restocking.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        @foreach ($lowStockPackages->take(6) as $package)
                            <span class="rounded-lg border border-amber-300/30 bg-black/20 px-3 py-2 text-xs font-semibold">
                                {{ $packageLabel($package) }}: {{ $package->available_license_stocks_count }} left
                            </span>
                        @endforeach

                        @if ($lowStockPackages->count() > 6)
                            <span class="rounded-lg border border-amber-300/30 bg-black/20 px-3 py-2 text-xs font-semibold">
                                +{{ $lowStockPackages->count() - 6 }} more
                            </span>
                        @endif
                    </div>
                </div>
            </section>
        @endif

        <section class="product-section mb-6 fade-up">
            <div class="mb-4">
                <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Bulk Add</p>
                <h2 class="mt-1 text-xl font-semibold text-white">Add License Keys</h2>
                <p class="mt-1 text-sm text-gray-400">Paste one key per line. Commas and semicolons also work.</p>
            </div>

            <form action="{{ route('admin.license-stocks.store') }}" method="POST" class="grid gap-4 lg:grid-cols-[360px_1fr_auto] lg:items-end">
                @csrf

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Package</span>
                    <select name="package_id" class="search-bar w-full" required>
                        <option value="">Select package</option>
                        @foreach ($packages as $package)
                            <option value="{{ $package->id }}" @selected(old('package_id') == $package->id)>
                                {{ $packageLabel($package) }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">License keys</span>
                    <textarea name="license_keys" rows="4" class="search-bar min-h-28 w-full resize-y"
                        placeholder="Put new license keys here&#10;One key per line" required>{{ old('license_keys') }}</textarea>
                </label>

                <button class="btn-footer h-12">Add Stock</button>
            </form>
        </section>

        @if ($editStock)
            <section class="product-section mb-6 fade-up">
                <div class="mb-4">
                    <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Edit</p>
                    <h2 class="mt-1 text-xl font-semibold text-white">Edit Unsold Key</h2>
                </div>

                <form action="{{ route('admin.license-stocks.update', $editStock) }}" method="POST"
                    class="grid gap-4 lg:grid-cols-[360px_1fr_auto] lg:items-end">
                    @csrf
                    @method('PATCH')

                    <label class="block">
                        <span class="mb-2 block text-xs font-semibold text-gray-400">Package</span>
                        <select name="package_id" class="search-bar w-full" required>
                            @foreach ($packages as $package)
                                <option value="{{ $package->id }}" @selected($editStock->package_id === $package->id)>
                                    {{ $packageLabel($package) }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-xs font-semibold text-gray-400">License key</span>
                        <input name="license_key" value="{{ old('license_key', $editStock->license_key) }}"
                            class="search-bar w-full" required maxlength="255">
                    </label>

                    <div class="flex gap-2">
                        <button class="btn-footer h-12">Save</button>
                        <a href="{{ route('admin.license-stocks.index') }}" class="btn-footer-secondary h-12">Cancel</a>
                    </div>
                </form>
            </section>
        @endif

        <section class="product-section mb-6 fade-up">
            <form id="stockFilterForm" method="GET" action="{{ route('admin.license-stocks.index') }}" class="grid gap-3 md:grid-cols-2 md:items-end xl:grid-cols-[1fr_1fr_1.45fr_0.85fr_auto]">
                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Search</span>
                    <input name="search" value="{{ request('search') }}" class="search-bar w-full"
                        placeholder="License key">
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Product</span>
                    <select name="product_id" class="search-bar w-full">
                        <option value="">All products</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected((string) request('product_id') === (string) $product->id)>
                                {{ $product->name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Package</span>
                    <select name="package_id" class="search-bar w-full">
                        <option value="">All packages</option>
                        @foreach ($packages as $package)
                            <option value="{{ $package->id }}" @selected((string) request('package_id') === (string) $package->id)>
                                {{ $packageLabel($package) }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Status</span>
                    <select name="status" class="search-bar w-full">
                        <option value="">All status</option>
                        <option value="available" @selected(request('status') === 'available')>Available</option>
                        <option value="sold" @selected(request('status') === 'sold')>Sold</option>
                    </select>
                </label>

                <div class="flex gap-2 md:col-span-2 xl:col-span-1">
                    <button class="btn-footer h-12 md:hidden">Filter</button>
                    <a href="{{ route('admin.license-stocks.index') }}" class="btn-footer-secondary h-12">Reset</a>
                </div>
            </form>
        </section>

        <div class="orders-table-wrap hidden md:block">
            <div class="flex items-center justify-between gap-3 border-b border-[#27272A] px-4 py-4">
                <div>
                    <h2 class="text-sm font-semibold text-white">Stock Records</h2>
                    <p class="mt-1 text-xs text-gray-500">Sold keys are locked for audit safety.</p>
                </div>
                <span class="rounded-lg border border-[#9333EA]/30 bg-[#9333EA]/10 px-3 py-1 text-xs font-semibold text-[#C084FC]">
                    {{ $stocks->total() }} records
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[1180px] text-sm">
                    <thead class="bg-[#111115] text-xs uppercase tracking-normal text-gray-500">
                        <tr>
                            <th class="p-4 text-left">License Key</th>
                            <th class="p-4 text-left">Product</th>
                            <th class="p-4 text-left">Package</th>
                            <th class="p-4 text-left">Status</th>
                            <th class="p-4 text-left">Created</th>
                            <th class="p-4 text-left">Sold</th>
                            <th class="p-4 text-left">Sold To</th>
                            <th class="p-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($stocks as $stock)
                            @php $soldUser = $stock->soldLicense?->user; @endphp

                            <tr class="orders-table-row">
                                <td class="p-4">
                                    <div class="max-w-[260px] truncate font-mono text-xs text-gray-300">{{ $stock->license_key }}</div>
                                </td>
                                <td class="p-4 font-semibold text-white">{{ $stock->product->name ?? '-' }}</td>
                                <td class="p-4 text-gray-300">{{ $stock->package->name ?? '-' }}</td>
                                <td class="p-4">
                                    <span class="status-pill {{ $stock->is_sold ? 'status-pill-cancelled' : 'status-pill-paid' }}">
                                        {{ $stock->is_sold ? 'Sold' : 'Available' }}
                                    </span>
                                </td>
                                <td class="p-4 text-xs text-gray-400">{{ $stock->created_at?->format('d M Y, H:i') ?? '-' }}</td>
                                <td class="p-4 text-xs text-gray-400">{{ $stock->sold_at?->format('d M Y, H:i') ?? '-' }}</td>
                                <td class="p-4">
                                    @if ($stock->is_sold && $soldUser)
                                        <div class="max-w-[180px] truncate text-sm font-semibold text-white">{{ $soldUser->name }}</div>
                                        <div class="max-w-[180px] truncate text-xs text-gray-500">{{ $soldUser->email }}</div>
                                    @elseif ($stock->is_sold)
                                        <span class="text-xs text-gray-500">Unknown user</span>
                                    @else
                                        <span class="text-xs text-gray-500">-</span>
                                    @endif
                                </td>
                                <td class="p-4 text-right">
                                    @if ($stock->is_sold)
                                        <span class="text-xs text-gray-500">Locked</span>
                                    @else
                                        <div class="inline-flex justify-end gap-2">
                                            <a href="{{ route('admin.license-stocks.index', array_merge(request()->query(), ['edit' => $stock->id])) }}"
                                                class="order-action">Edit</a>

                                            <form action="{{ route('admin.license-stocks.destroy', $stock) }}" method="POST"
                                                onsubmit="return confirm('Delete this unsold license key?')">
                                                @csrf
                                                @method('DELETE')
                                                <button class="order-action order-action-danger">Delete</button>
                                            </form>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="p-8">
                                    <div class="empty-state">No stock found</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-4 md:hidden">
            @forelse ($stocks as $stock)
                @php $soldUser = $stock->soldLicense?->user; @endphp

                <article class="order-mobile-card motion-card">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="text-[10px] uppercase tracking-normal text-gray-500">License Key</div>
                            <div class="mt-1 truncate font-mono text-xs text-gray-300">{{ $stock->license_key }}</div>
                        </div>
                        <span class="status-pill {{ $stock->is_sold ? 'status-pill-cancelled' : 'status-pill-paid' }}">
                            {{ $stock->is_sold ? 'Sold' : 'Available' }}
                        </span>
                    </div>

                    <div class="mt-4 grid gap-2 text-sm">
                        <div class="font-semibold text-white">{{ $stock->product->name ?? '-' }}</div>
                        <div class="text-gray-400">{{ $stock->package->name ?? '-' }}</div>
                        <div class="grid gap-1 text-xs text-gray-500">
                            <span>Created: {{ $stock->created_at?->format('d M Y, H:i') ?? '-' }}</span>
                            <span>Sold: {{ $stock->sold_at?->format('d M Y, H:i') ?? '-' }}</span>
                            <span>
                                Sold to:
                                @if ($stock->is_sold && $soldUser)
                                    {{ $soldUser->name }} ({{ $soldUser->email }})
                                @elseif ($stock->is_sold)
                                    Unknown user
                                @else
                                    -
                                @endif
                            </span>
                        </div>
                    </div>

                    <div class="mt-4 flex gap-2">
                        @if ($stock->is_sold)
                            <span class="text-xs text-gray-500">Locked</span>
                        @else
                            <a href="{{ route('admin.license-stocks.index', array_merge(request()->query(), ['edit' => $stock->id])) }}"
                                class="order-action">Edit</a>
                            <form action="{{ route('admin.license-stocks.destroy', $stock) }}" method="POST"
                                onsubmit="return confirm('Delete this unsold license key?')">
                                @csrf
                                @method('DELETE')
                                <button class="order-action order-action-danger">Delete</button>
                            </form>
                        @endif
                    </div>
                </article>
            @empty
                <div class="empty-state">No stock found</div>
            @endforelse
        </div>

        @include('partials.pagination', [
            'paginator' => $stocks,
            'label' => 'License stock pagination',
            'itemLabel' => 'keys',
        ])
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('stockFilterForm');
            const lowStockCount = @json($lowStockPackages->count());

            if (lowStockCount > 0) {
                window.setTimeout(() => {
                    window.showAppToast?.(
                        'Low stock',
                        `${lowStockCount} package${lowStockCount === 1 ? '' : 's'} need restocking.`, {
                            variant: 'warning',
                            duration: 5000
                        }
                    );
                }, 350);
            }

            if (!form) return;

            let searchTimeout;
            const submitFilters = () => {
                const pageInput = form.querySelector('input[name="page"]');

                if (pageInput) {
                    pageInput.remove();
                }

                form.requestSubmit();
            };

            form.querySelectorAll('select[name="product_id"], select[name="package_id"], select[name="status"]').forEach((select) => {
                select.addEventListener('change', submitFilters);
            });

            form.querySelector('input[name="search"]')?.addEventListener('input', () => {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(submitFilters, 450);
            });
        });
    </script>
@endsection
