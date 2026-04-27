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
                    <a href="/orders" class="btn-footer-secondary">Orders</a>
                    <a href="/licenses" class="btn-footer">Licenses</a>
                </div>
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-3">
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
                        placeholder="AKSA-KEY-001&#10;AKSA-KEY-002" required>{{ old('license_keys') }}</textarea>
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
            <form method="GET" action="{{ route('admin.license-stocks.index') }}" class="grid gap-3 md:grid-cols-4 md:items-end">
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
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Status</span>
                    <select name="status" class="search-bar w-full">
                        <option value="">All status</option>
                        <option value="available" @selected(request('status') === 'available')>Available</option>
                        <option value="sold" @selected(request('status') === 'sold')>Sold</option>
                    </select>
                </label>

                <div class="flex gap-2">
                    <button class="btn-footer h-12">Filter</button>
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
                <table class="w-full min-w-[920px] text-sm">
                    <thead class="bg-[#111115] text-xs uppercase tracking-normal text-gray-500">
                        <tr>
                            <th class="p-4 text-left">License Key</th>
                            <th class="p-4 text-left">Product</th>
                            <th class="p-4 text-left">Package</th>
                            <th class="p-4 text-left">Status</th>
                            <th class="p-4 text-left">Created</th>
                            <th class="p-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($stocks as $stock)
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
                                <td colspan="6" class="p-8">
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

        @if ($stocks->hasPages())
            <nav class="order-pagination mt-5" aria-label="License stock pagination">
                <div class="text-xs text-gray-500">
                    Showing {{ $stocks->firstItem() }}-{{ $stocks->lastItem() }} of {{ $stocks->total() }} keys
                </div>

                <div class="flex flex-wrap items-center justify-center gap-2 sm:justify-end">
                    @if ($stocks->onFirstPage())
                        <span class="order-pagination-link opacity-45">Previous</span>
                    @else
                        <a href="{{ $stocks->previousPageUrl() }}" class="order-pagination-link">Previous</a>
                    @endif

                    @foreach ($stocks->getUrlRange(max(1, $stocks->currentPage() - 2), min($stocks->lastPage(), $stocks->currentPage() + 2)) as $page => $url)
                        @if ($page === $stocks->currentPage())
                            <span class="order-pagination-link is-active">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="order-pagination-link">{{ $page }}</a>
                        @endif
                    @endforeach

                    @if ($stocks->hasMorePages())
                        <a href="{{ $stocks->nextPageUrl() }}" class="order-pagination-link">Next</a>
                    @else
                        <span class="order-pagination-link opacity-45">Next</span>
                    @endif
                </div>
            </nav>
        @endif
    </div>
@endsection
