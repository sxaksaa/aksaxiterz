@extends('layouts.app')

@section('content')
    @php
        $formatIdr = fn ($value) => 'Rp ' . number_format((int) $value, 0, ',', '.');
        $formatUsdt = fn ($value) => $value === null ? '-' : rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.') . ' USDT';
        $canDeleteProduct = $orderCount === 0 && $product->license_stocks_count === 0;
    @endphp

    <div class="page-shell py-6 md:py-10">
        <section class="orders-hero fade-up mb-6">
            <div class="grid gap-5 lg:grid-cols-[1fr_auto] lg:items-end">
                <div>
                    <p class="mb-2 text-sm font-semibold text-[#C084FC]">Admin Catalog</p>
                    <h1 class="text-3xl font-bold tracking-normal md:text-4xl">{{ $product->name }}</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                        Update public product details and package prices used by checkout.
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('admin.products.index') }}" class="btn-footer-secondary">Catalog</a>
                    <a href="{{ route('products.show', $product) }}" class="btn-footer-secondary">View Product</a>
                    <a href="{{ route('admin.license-stocks.index', ['product_id' => $product->id]) }}" class="btn-footer">Stock</a>
                </div>
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $product->packages_count }}</div>
                    <div class="mt-1 text-xs text-gray-400">Packages</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $product->available_license_stocks_count }}</div>
                    <div class="mt-1 text-xs text-gray-400">Available keys</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $product->license_stocks_count }}</div>
                    <div class="mt-1 text-xs text-gray-400">Total keys</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $orderCount }}</div>
                    <div class="mt-1 text-xs text-gray-400">Orders</div>
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
                <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Product</p>
                <h2 class="mt-1 text-xl font-semibold text-white">Product Details</h2>
            </div>

            <form action="{{ route('admin.products.update', $product) }}" method="POST" class="grid gap-4 lg:grid-cols-2">
                @csrf
                @method('PATCH')

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Product name</span>
                    <input name="name" value="{{ old('name', $product->name) }}" class="search-bar w-full"
                        required maxlength="120">
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Category</span>
                    <select name="category_id" class="search-bar w-full" required>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) old('category_id', $product->category_id) === (string) $category->id)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Slug</span>
                    <input name="slug" value="{{ old('slug', $product->slug) }}" class="search-bar w-full"
                        maxlength="160">
                </label>

                <label class="block lg:row-span-2">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Description</span>
                    <textarea name="description" rows="4" class="search-bar min-h-28 w-full resize-y"
                        required>{{ old('description', $product->description) }}</textarea>
                </label>

                <div class="flex flex-wrap items-end gap-2">
                    <button class="btn-footer h-12">Save Product</button>
                    <a href="{{ route('admin.products.index') }}" class="btn-footer-secondary h-12">Cancel</a>
                </div>
            </form>
        </section>

        <section class="product-section mb-6 fade-up">
            <div class="mb-4">
                <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Prices</p>
                <h2 class="mt-1 text-xl font-semibold text-white">Package Prices</h2>
            </div>

            <div class="grid gap-4">
                @forelse ($product->packages as $package)
                    @php $canDeletePackage = $package->orders_count === 0 && $package->license_stocks_count === 0; @endphp

                    <div class="rounded-xl border border-[#27272A] bg-black/15 p-4">
                        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="font-semibold text-white">{{ $package->name }}</h3>
                                <p class="mt-1 text-xs text-gray-500">
                                    {{ $formatIdr($package->price) }} / {{ $formatUsdt($package->price_usdt) }} /
                                    {{ $package->available_license_stocks_count }} available keys
                                </p>
                            </div>

                            @if ($canDeletePackage)
                                <form action="{{ route('admin.packages.destroy', $package) }}" method="POST"
                                    onsubmit="return confirm('Delete this package?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="order-action order-action-danger">Delete</button>
                                </form>
                            @else
                                <span class="text-xs text-gray-500">Locked by stock/order history</span>
                            @endif
                        </div>

                        <form action="{{ route('admin.packages.update', $package) }}" method="POST"
                            class="grid gap-3 md:grid-cols-[1fr_0.7fr_0.7fr_auto] md:items-end">
                            @csrf
                            @method('PATCH')

                            <label class="block">
                                <span class="mb-2 block text-xs font-semibold text-gray-400">Package name</span>
                                <input name="package_name" value="{{ $package->name }}" class="search-bar w-full"
                                    required maxlength="80">
                            </label>

                            <label class="block">
                                <span class="mb-2 block text-xs font-semibold text-gray-400">IDR price</span>
                                <input name="package_price" value="{{ $package->price }}" type="number" min="0"
                                    max="999999999" step="1" class="search-bar w-full" required>
                            </label>

                            <label class="block">
                                <span class="mb-2 block text-xs font-semibold text-gray-400">USDT price</span>
                                <input name="package_price_usdt" value="{{ $package->price_usdt }}" type="number"
                                    min="0" max="999999.9999" step="0.0001" class="search-bar w-full">
                            </label>

                            <button class="btn-footer h-12">Save</button>
                        </form>
                    </div>
                @empty
                    <div class="empty-state">No packages yet</div>
                @endforelse
            </div>
        </section>

        <section class="product-section mb-6 fade-up">
            <div class="mb-4">
                <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">New Package</p>
                <h2 class="mt-1 text-xl font-semibold text-white">Add Package Price</h2>
            </div>

            <form action="{{ route('admin.products.packages.store', $product) }}" method="POST"
                class="grid gap-3 md:grid-cols-[1fr_0.7fr_0.7fr_auto] md:items-end">
                @csrf

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Package name</span>
                    <input name="package_name" value="{{ old('package_name') }}" class="search-bar w-full"
                        placeholder="30 Days" required maxlength="80">
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">IDR price</span>
                    <input name="package_price" value="{{ old('package_price') }}" type="number" min="0"
                        max="999999999" step="1" class="search-bar w-full" placeholder="250000" required>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">USDT price</span>
                    <input name="package_price_usdt" value="{{ old('package_price_usdt') }}" type="number"
                        min="0" max="999999.9999" step="0.0001" class="search-bar w-full" placeholder="15">
                </label>

                <button class="btn-footer h-12">Add Package</button>
            </form>
        </section>

        <section class="product-section fade-up">
            <div class="grid gap-4 md:grid-cols-[1fr_auto] md:items-center">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-normal text-red-300">Delete</p>
                    <h2 class="mt-1 text-xl font-semibold text-white">Remove Empty Product</h2>
                    <p class="mt-2 text-sm leading-6 text-gray-400">
                        {{ $canDeleteProduct ? 'This product has no orders or license stock.' : 'Products with orders or license stock stay locked for audit history.' }}
                    </p>
                </div>

                <form action="{{ route('admin.products.destroy', $product) }}" method="POST"
                    onsubmit="return confirm('Delete this product?')">
                    @csrf
                    @method('DELETE')
                    <button class="order-action order-action-danger {{ $canDeleteProduct ? '' : 'cursor-not-allowed opacity-50' }}"
                        @disabled(! $canDeleteProduct)>
                        Delete Product
                    </button>
                </form>
            </div>
        </section>
    </div>
@endsection
