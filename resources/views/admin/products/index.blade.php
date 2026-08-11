@extends('layouts.app')

@section('content')
    @php
        $formatIdr = fn ($value) => 'Rp ' . number_format((int) $value, 0, ',', '.');
        $formatUsdt = fn ($value) => $value === null ? '-' : rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.') . ' USDT';
    @endphp

    <div class="page-shell py-6 md:py-10">
        <section class="orders-hero fade-up mb-6">
            <div>
                <div>
                    <p class="mb-2 text-sm font-semibold text-aksa-accent">Admin</p>
                    <h1 class="text-3xl font-bold tracking-normal md:text-4xl">Catalog</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                        Manage product names, descriptions, categories, and package prices.
                    </p>
                </div>
            </div>

            <div class="admin-stat-grid mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['products'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Products</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['packages'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Packages</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['ready_products'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Ready products</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['updating_products'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Updating products</div>
                </div>
            </div>
        </section>

        @if (session('info'))
            <div class="mb-4 rounded-xl border border-aksa-accent-30 bg-aksa-accent-10 px-4 py-3 text-sm text-aksa-accent-soft">
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
                <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">New Product</p>
                <h2 class="mt-1 text-xl font-semibold text-white">Add Catalog Item</h2>
            </div>

            <form action="{{ route('admin.products.store') }}" method="POST" class="grid gap-4 lg:grid-cols-2">
                @csrf

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Product name</span>
                    <input name="name" value="{{ old('name') }}" class="search-bar w-full"
                        placeholder="Enter product name" required maxlength="120">
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Category</span>
                    <select name="category_id" class="search-bar w-full" required>
                        <option value="">Select category</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) old('category_id') === (string) $category->id)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Status</span>
                    <select name="status" class="search-bar w-full" required>
                        @foreach ($statusOptions as $statusValue => $statusLabel)
                            <option value="{{ $statusValue }}" @selected(old('status', 'ready') === $statusValue)>
                                {{ $statusLabel }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Visibility</span>
                    <select name="is_visible" class="search-bar w-full" required>
                        <option value="1" @selected((string) old('is_visible', '1') === '1')>Public</option>
                        <option value="0" @selected((string) old('is_visible', '1') === '0')>Hidden</option>
                    </select>
                </label>

                <label class="block lg:col-span-2">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Description</span>
                    <textarea name="description" rows="4" class="search-bar min-h-28 w-full resize-y"
                        placeholder="Short public product description" required>{{ old('description') }}</textarea>
                </label>

                <div class="flex items-end lg:col-span-2">
                    <button class="btn-footer h-12">
                        <x-ui.icon name="package-plus" class="h-4 w-4" />
                        <span>Create Product</span>
                    </button>
                </div>
            </form>
        </section>

        <section class="product-section mb-6 fade-up">
            <form id="catalogFilterForm" method="GET" action="{{ route('admin.products.index') }}"
                class="grid gap-3 md:grid-cols-2 md:items-end xl:grid-cols-[1fr_0.7fr_0.55fr_auto]">
                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Search</span>
                    <input name="search" value="{{ request('search') }}" class="search-bar w-full"
                        placeholder="Product or description">
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Category</span>
                    <select name="category_id" class="search-bar w-full">
                        <option value="">All categories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) request('category_id') === (string) $category->id)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Visibility</span>
                    <select name="visibility" class="search-bar w-full">
                        <option value="">All products</option>
                        <option value="visible" @selected(request('visibility') === 'visible')>Public</option>
                        <option value="hidden" @selected(request('visibility') === 'hidden')>Hidden</option>
                    </select>
                </label>

                <div class="flex gap-2">
                    <button type="submit" class="btn-footer h-12">
                        <x-ui.icon name="filter" class="h-4 w-4" />
                        <span>Filter</span>
                    </button>
                    <a href="{{ route('admin.products.index') }}" class="btn-footer-secondary h-12">
                        <x-ui.icon name="rotate-ccw" class="h-4 w-4" />
                        <span>Reset</span>
                    </a>
                </div>
            </form>
        </section>

        <div class="orders-table-wrap hidden lg:block">
            <div class="flex items-center justify-between gap-3 border-b border-[#27272A] px-4 py-4">
                <div>
                    <h2 class="text-sm font-semibold text-white">Catalog Items</h2>
                    <p class="mt-1 text-xs text-gray-500">Edit a product to update package prices.</p>
                </div>
                <span class="rounded-lg border border-aksa-accent-30 bg-aksa-accent-10 px-3 py-1 text-xs font-semibold text-aksa-accent">
                    {{ $products->total() }} records
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[1120px] text-sm">
                    <thead class="bg-[#111115] text-xs uppercase tracking-normal text-gray-500">
                        <tr>
                            <th class="p-4 text-left">Product</th>
                            <th class="p-4 text-left">Category</th>
                            <th class="p-4 text-left">Status</th>
                            <th class="p-4 text-left">Packages</th>
                            <th class="p-4 text-left">Stock</th>
                            <th class="p-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($products as $product)
                            @php
                                $statusBadgeClass = $product->status === \App\Models\Product::STATUS_UPDATING
                                    ? 'product-status-badge-updating'
                                    : 'product-status-badge-ready';
                            @endphp
                            <tr class="orders-table-row">
                                <td class="p-4">
                                    <div class="font-semibold text-white">{{ $product->name }}</div>
                                    <div class="mt-2 max-w-[300px] text-xs leading-5 text-gray-400">
                                        {{ $product->description }}
                                    </div>
                                </td>
                                <td class="p-4 text-gray-300">{{ $product->category->name ?? '-' }}</td>
                                <td class="p-4">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="product-status-badge product-status-badge-static {{ $statusBadgeClass }}">
                                            {{ $product->status_label }}
                                        </span>
                                        @unless ($product->is_visible)
                                            <span class="inline-flex items-center rounded-lg border border-gray-600/50 bg-gray-500/10 px-2.5 py-1 text-xs font-semibold text-gray-300">
                                                Hidden
                                            </span>
                                        @endunless
                                    </div>
                                </td>
                                <td class="p-4">
                                    <div class="grid gap-2">
                                        @forelse ($product->packages as $package)
                                            <div class="rounded-lg border border-[#27272A] bg-black/15 px-3 py-2">
                                                <div class="font-semibold text-white">{{ $package->name }}</div>
                                                <div class="mt-1 text-xs text-gray-400">
                                                    {{ $formatIdr($package->price) }} / {{ $formatUsdt($package->price_usdt) }}
                                                </div>
                                                <div class="mt-1 text-xs text-gray-500">
                                                    {{ $package->available_license_stocks_count }} keys
                                                </div>
                                            </div>
                                        @empty
                                            <span class="text-xs text-gray-500">No packages</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td class="p-4">
                                    <div class="font-semibold text-white">{{ $product->available_license_stocks_count }}</div>
                                    <div class="mt-1 text-xs text-gray-500">
                                        {{ $product->license_stocks_count }} total keys
                                    </div>
                                </td>
                                <td class="p-4 text-right">
                                    <a href="{{ route('admin.products.edit', $product) }}" class="order-action">
                                        <x-ui.icon name="edit-3" class="h-4 w-4" />
                                        <span>Edit</span>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-8">
                                    <div class="empty-state">No products found</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-4 lg:hidden">
            @forelse ($products as $product)
                @php
                    $statusBadgeClass = $product->status === \App\Models\Product::STATUS_UPDATING
                        ? 'product-status-badge-updating'
                        : 'product-status-badge-ready';
                @endphp
                <article class="order-mobile-card motion-card">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-semibold text-white">{{ $product->name }}</div>
                            <div class="mt-1 text-xs text-gray-400">{{ $product->category->name ?? '-' }}</div>
                        </div>
                        <div class="flex shrink-0 flex-wrap justify-end gap-2">
                            <span class="product-status-badge product-status-badge-static {{ $statusBadgeClass }}">
                                {{ $product->status_label }}
                            </span>
                            @unless ($product->is_visible)
                                <span class="inline-flex items-center rounded-lg border border-gray-600/50 bg-gray-500/10 px-2.5 py-1 text-xs font-semibold text-gray-300">
                                    Hidden
                                </span>
                            @endunless
                        </div>
                    </div>

                    <div class="mt-4 grid gap-2 text-sm">
                        @forelse ($product->packages as $package)
                            <div class="rounded-lg border border-[#27272A] bg-black/15 px-3 py-2">
                                <div class="font-semibold text-white">{{ $package->name }}</div>
                                <div class="mt-1 text-xs text-gray-400">
                                    {{ $formatIdr($package->price) }} / {{ $formatUsdt($package->price_usdt) }}
                                </div>
                            </div>
                        @empty
                            <span class="text-xs text-gray-500">No packages</span>
                        @endforelse
                    </div>

                    <a href="{{ route('admin.products.edit', $product) }}" class="order-action mt-4 w-full">
                        <x-ui.icon name="edit-3" class="h-4 w-4" />
                        <span>Edit</span>
                    </a>
                </article>
            @empty
                <div class="empty-state">No products found</div>
            @endforelse
        </div>

        @include('partials.pagination', [
            'paginator' => $products,
            'label' => 'Catalog pagination',
            'itemLabel' => 'products',
        ])
    </div>

@endsection
