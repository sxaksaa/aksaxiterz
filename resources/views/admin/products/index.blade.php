@extends('layouts.app')

@section('content')
    @php
        $addingProduct = old('catalog_action') === 'create';
        $createValue = fn ($field, $default = '') => $addingProduct ? old($field, $default) : $default;
        $filterCount = collect(request()->only(['search', 'category_id', 'visibility']))->filter(fn ($value) => filled($value))->count();
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

        <div class="mb-4 flex flex-wrap gap-3">
            <button type="button" class="btn-footer-secondary" data-catalog-panel-toggle aria-controls="catalogAddPanel" aria-expanded="{{ $addingProduct ? 'true' : 'false' }}">
                <x-ui.icon name="package-plus" class="h-4 w-4" />
                <span>Add product</span>
                <x-ui.icon name="chevron-down" class="catalog-chevron h-4 w-4" />
            </button>
            <button type="button" class="btn-footer-secondary" data-catalog-panel-toggle aria-controls="catalogSearchPanel" aria-expanded="false">
                <x-ui.icon name="search" class="h-4 w-4" />
                <span>Search &amp; filter{{ $filterCount ? ' ('.$filterCount.' active)' : '' }}</span>
                <x-ui.icon name="chevron-down" class="catalog-chevron h-4 w-4" />
            </button>
        </div>

        <div id="catalogAddPanel" class="catalog-disclosure-panel" @if (! $addingProduct) hidden @endif>
            <div class="catalog-disclosure-spacing">
        <section class="product-section">
            <div class="mb-4">
                <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">New Product</p>
                <h2 class="mt-1 text-xl font-semibold text-white">Add Catalog Item</h2>
            </div>

            <form action="{{ route('admin.products.store') }}" method="POST" class="grid gap-4 lg:grid-cols-2">
                @csrf
                <input type="hidden" name="catalog_action" value="create">

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Product name</span>
                    <input name="name" value="{{ $createValue('name') }}" class="search-bar w-full"
                        placeholder="Enter product name" required maxlength="120">
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Category</span>
                    <select name="category_id" class="search-bar w-full" required>
                        <option value="">Select category</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) $createValue('category_id') === (string) $category->id)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Status</span>
                    <select name="status" class="search-bar w-full" required>
                        @foreach ($statusOptions as $statusValue => $statusLabel)
                            <option value="{{ $statusValue }}" @selected($createValue('status', 'ready') === $statusValue)>
                                {{ $statusLabel }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Visibility</span>
                    <select name="is_visible" class="search-bar w-full" required>
                        <option value="1" @selected((string) $createValue('is_visible', '1') === '1')>Public</option>
                        <option value="0" @selected((string) $createValue('is_visible', '1') === '0')>Hidden</option>
                    </select>
                </label>

                <label class="block lg:col-span-2">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Description</span>
                    <textarea name="description" rows="4" class="search-bar min-h-28 w-full resize-y"
                        placeholder="Short public product description" required>{{ $createValue('description') }}</textarea>
                </label>

                <div class="flex items-end lg:col-span-2">
                    <button class="btn-footer h-12">
                        <x-ui.icon name="package-plus" class="h-4 w-4" />
                        <span>Create Product</span>
                    </button>
                </div>
            </form>
        </section>
            </div>
        </div>

        <div id="catalogSearchPanel" class="catalog-disclosure-panel" hidden>
            <div class="catalog-disclosure-spacing">
        <section class="product-section">
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
            </div>
        </div>

        <section class="space-y-4">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-white">Catalog Items</h2>
                    <p class="mt-1 text-xs text-gray-500">Click a product name to manage it. Use Edit and Save to update its details.</p>
                </div>
                <span class="rounded-lg border border-aksa-accent-30 bg-aksa-accent-10 px-3 py-1 text-xs font-semibold text-aksa-accent">
                    {{ $products->total() }} records
                </span>
            </div>
            @forelse ($products as $product)
                @include('admin.products.quick-edit')
            @empty
                <div class="empty-state">No products found</div>
            @endforelse
        </section>

        @include('partials.pagination', [
            'paginator' => $products,
            'label' => 'Catalog pagination',
            'itemLabel' => 'products',
        ])
    </div>

@endsection
