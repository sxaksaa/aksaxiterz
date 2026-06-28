@extends('layouts.app')

@section('content')
    @php
        $packageName = function ($package) {
            $name = str_replace(['1 Hari', '7 Hari', '30 Hari', 'Hari'], ['1 Day', '7 Days', '30 Days', 'Days'], $package->name);

            return $name;
        };
        $packageLabel = function ($package) use ($packageName) {
            return ($package->product->name ?? 'Product') . ' - ' . $packageName($package);
        };
        $selectedCreateProductId = old('product_id');
        $selectedCreatePackage = old('package_id') ? $packages->firstWhere('id', (int) old('package_id')) : null;

        if (! $selectedCreateProductId && $selectedCreatePackage) {
            $selectedCreateProductId = $selectedCreatePackage->product_id;
        }

        $selectedEditProductId = old('product_id', $editStock?->product_id);
    @endphp

    <div class="page-shell py-6 md:py-10">
        <section class="orders-hero fade-up mb-6">
            <div>
                <div>
                    <p class="mb-2 text-sm font-semibold text-[#C084FC]">Admin</p>
                    <h1 class="text-3xl font-bold tracking-normal md:text-4xl">License Stock</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                        Add, search, and maintain unsold license keys before they are delivered to customers.
                    </p>
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
                    <div class="text-xl font-semibold text-white">{{ $stats['reserved'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Reserved</div>
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

            <form action="{{ route('admin.license-stocks.store') }}" method="POST"
                class="grid gap-4 lg:grid-cols-[260px_260px_1fr_auto] lg:items-end">
                @csrf

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Product</span>
                    <select name="product_id" class="search-bar w-full" required data-package-product-select
                        data-package-target="stockCreatePackage">
                        <option value="">Select product</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected((string) $selectedCreateProductId === (string) $product->id)>
                                {{ $product->name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Package</span>
                    <select id="stockCreatePackage" name="package_id" class="search-bar w-full" required
                        data-package-select data-require-product="true" data-empty-label="Select product first"
                        data-selected-empty-label="Select package">
                        <option value="">Select product first</option>
                        @foreach ($packages as $package)
                            <option value="{{ $package->id }}" data-product-id="{{ $package->product_id }}"
                                data-duration-label="{{ $packageName($package) }}"
                                data-full-label="{{ $packageLabel($package) }}"
                                @selected((string) old('package_id') === (string) $package->id)>
                                {{ $packageName($package) }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">License keys</span>
                    <textarea name="license_keys" rows="1" class="search-bar stock-key-textarea w-full"
                        data-stock-key-input
                        placeholder="Put new license keys here&#10;One key per line" required>{{ old('license_keys') }}</textarea>
                </label>

                <button class="btn-footer h-12">
                    <x-ui.icon name="package-plus" class="h-4 w-4" />
                    <span>Add Stock</span>
                </button>
            </form>
        </section>

        @if ($editStock)
            <section class="product-section mb-6 fade-up">
                <div class="mb-4">
                    <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Edit</p>
                    <h2 class="mt-1 text-xl font-semibold text-white">Edit Unsold Key</h2>
                </div>

                <form action="{{ route('admin.license-stocks.update', $editStock) }}" method="POST"
                    class="grid gap-4 lg:grid-cols-[260px_260px_1fr_auto] lg:items-end">
                    @csrf
                    @method('PATCH')

                    <label class="block">
                        <span class="mb-2 block text-xs font-semibold text-gray-400">Product</span>
                        <select name="product_id" class="search-bar w-full" required data-package-product-select
                            data-package-target="stockEditPackage">
                            <option value="">Select product</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}" @selected((string) $selectedEditProductId === (string) $product->id)>
                                    {{ $product->name }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-xs font-semibold text-gray-400">Package</span>
                        <select id="stockEditPackage" name="package_id" class="search-bar w-full" required
                            data-package-select data-require-product="true" data-empty-label="Select product first"
                            data-selected-empty-label="Select package">
                            @foreach ($packages as $package)
                                <option value="{{ $package->id }}" data-product-id="{{ $package->product_id }}"
                                    data-duration-label="{{ $packageName($package) }}"
                                    data-full-label="{{ $packageLabel($package) }}"
                                    @selected((string) old('package_id', $editStock->package_id) === (string) $package->id)>
                                    {{ $packageName($package) }}
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
                        <button class="btn-footer h-12">
                            <x-ui.icon name="save" class="h-4 w-4" />
                            <span>Save</span>
                        </button>
                        <a href="{{ route('admin.license-stocks.index') }}" class="btn-footer-secondary h-12">
                            <x-ui.icon name="x" class="h-4 w-4" />
                            <span>Cancel</span>
                        </a>
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
                    <select name="product_id" class="search-bar w-full" data-package-product-select
                        data-package-target="stockFilterPackage">
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
                    <select id="stockFilterPackage" name="package_id" class="search-bar w-full" data-package-select
                        data-require-product="true" data-empty-label="Select product first"
                        data-selected-empty-label="All packages">
                        <option value="">Select product first</option>
                        @foreach ($packages as $package)
                            <option value="{{ $package->id }}" data-product-id="{{ $package->product_id }}"
                                data-duration-label="{{ $packageName($package) }}"
                                data-full-label="{{ $packageLabel($package) }}"
                                @selected((string) request('package_id') === (string) $package->id)>
                                {{ request('product_id') ? $packageName($package) : $packageLabel($package) }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Status</span>
                    <select name="status" class="search-bar w-full">
                        <option value="">All status</option>
                        <option value="available" @selected(request('status') === 'available')>Available</option>
                        <option value="reserved" @selected(request('status') === 'reserved')>Reserved</option>
                        <option value="sold" @selected(request('status') === 'sold')>Sold</option>
                    </select>
                </label>

                <div class="flex gap-2 md:col-span-2 xl:col-span-1">
                    <button class="btn-footer h-12">
                        <x-ui.icon name="filter" class="h-4 w-4" />
                        <span>Filter</span>
                    </button>
                    <a href="{{ route('admin.license-stocks.index') }}" class="btn-footer-secondary h-12">
                        <x-ui.icon name="rotate-ccw" class="h-4 w-4" />
                        <span>Reset</span>
                    </a>
                </div>
            </form>
        </section>

        <div class="orders-table-wrap hidden lg:block">
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
                            @php
                                $soldUser = $stock->soldLicense?->user;
                                $isReserved = $stock->isReserved();
                                $statusLabel = $stock->is_sold ? 'Sold' : ($isReserved ? 'Reserved' : 'Available');
                                $statusClass = $stock->is_sold ? 'status-pill-cancelled' : ($isReserved ? 'status-pill-pending' : 'status-pill-paid');
                            @endphp

                            <tr class="orders-table-row">
                                <td class="p-4">
                                    <div class="max-w-[260px] truncate font-mono text-xs text-gray-300">{{ $stock->license_key }}</div>
                                </td>
                                <td class="p-4 font-semibold text-white">{{ $stock->product->name ?? '-' }}</td>
                                <td class="p-4 text-gray-300">{{ $stock->package->name ?? '-' }}</td>
                                <td class="p-4">
                                    <span class="status-pill {{ $statusClass }}">
                                        {{ $statusLabel }}
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
                                    @elseif ($isReserved)
                                        <span class="text-xs text-gray-500">{{ $stock->reservedOrder?->user?->email ?? 'Reserved order' }}</span>
                                    @else
                                        <span class="text-xs text-gray-500">-</span>
                                    @endif
                                </td>
                                <td class="p-4 text-right">
                                    @if ($stock->is_sold || $isReserved)
                                        <span class="text-xs text-gray-500">Locked</span>
                                    @else
                                        <div class="inline-flex justify-end gap-2">
                                            <a href="{{ route('admin.license-stocks.index', array_merge(request()->query(), ['edit' => $stock->id])) }}"
                                                class="order-action">
                                                <x-ui.icon name="edit-3" class="h-4 w-4" />
                                                <span>Edit</span>
                                            </a>

                                            <form action="{{ route('admin.license-stocks.destroy', $stock) }}" method="POST"
                                                data-confirm="Delete this unsold license key?">
                                                @csrf
                                                @method('DELETE')
                                                <button class="order-action order-action-danger">
                                                    <x-ui.icon name="trash-2" class="h-4 w-4" />
                                                    <span>Delete</span>
                                                </button>
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

        <div class="space-y-4 lg:hidden">
            @forelse ($stocks as $stock)
                @php
                    $soldUser = $stock->soldLicense?->user;
                    $isReserved = $stock->isReserved();
                    $statusLabel = $stock->is_sold ? 'Sold' : ($isReserved ? 'Reserved' : 'Available');
                    $statusClass = $stock->is_sold ? 'status-pill-cancelled' : ($isReserved ? 'status-pill-pending' : 'status-pill-paid');
                @endphp

                <article class="order-mobile-card motion-card">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="text-[10px] uppercase tracking-normal text-gray-500">License Key</div>
                            <div class="mt-1 truncate font-mono text-xs text-gray-300">{{ $stock->license_key }}</div>
                        </div>
                        <span class="status-pill {{ $statusClass }}">
                            {{ $statusLabel }}
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
                                @elseif ($isReserved)
                                    {{ $stock->reservedOrder?->user?->email ?? 'Reserved order' }}
                                @else
                                    -
                                @endif
                            </span>
                        </div>
                    </div>

                    <div class="mt-4 flex gap-2">
                        @if ($stock->is_sold || $isReserved)
                            <span class="text-xs text-gray-500">Locked</span>
                        @else
                            <a href="{{ route('admin.license-stocks.index', array_merge(request()->query(), ['edit' => $stock->id])) }}"
                                class="order-action">
                                <x-ui.icon name="edit-3" class="h-4 w-4" />
                                <span>Edit</span>
                            </a>
                            <form action="{{ route('admin.license-stocks.destroy', $stock) }}" method="POST"
                                data-confirm="Delete this unsold license key?">
                                @csrf
                                @method('DELETE')
                                <button class="order-action order-action-danger">
                                    <x-ui.icon name="trash-2" class="h-4 w-4" />
                                    <span>Delete</span>
                                </button>
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

    <script nonce="{{ request()->attributes->get('csp_nonce') }}">
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('stockFilterForm');
            const syncPackageOptions = (productSelect, packageSelect, options = {}) => {
                if (!productSelect || !packageSelect) return;

                const selectedProductId = productSelect.value;
                const requireProduct = packageSelect.dataset.requireProduct === 'true';
                const currentPackageId = packageSelect.value;
                let currentPackageStillVisible = currentPackageId === '';

                packageSelect.querySelectorAll('option[data-product-id]').forEach((option) => {
                    const matchesProduct = selectedProductId === '' || option.dataset.productId === selectedProductId;
                    const visible = requireProduct && selectedProductId === '' ? false : matchesProduct;

                    option.hidden = !visible;
                    option.disabled = !visible;
                    option.textContent = selectedProductId ? option.dataset.durationLabel : option.dataset.fullLabel;

                    if (visible && option.value === currentPackageId) {
                        currentPackageStillVisible = true;
                    }
                });

                if (!currentPackageStillVisible) {
                    packageSelect.value = '';
                }

                packageSelect.disabled = requireProduct && selectedProductId === '';
                const emptyOption = packageSelect.querySelector('option[value=""]');

                if (emptyOption) {
                    emptyOption.replaceChildren(
                        document.createTextNode(
                            selectedProductId ?
                                (packageSelect.dataset.selectedEmptyLabel || 'Select package') :
                                (packageSelect.dataset.emptyLabel || 'Select product first')
                        )
                    );
                }

                if (options.clearPackage) {
                    packageSelect.value = '';
                }
            };

            document.querySelectorAll('[data-package-product-select]').forEach((productSelect) => {
                const packageSelect = document.getElementById(productSelect.dataset.packageTarget);

                syncPackageOptions(productSelect, packageSelect);

                productSelect.addEventListener('change', () => {
                    syncPackageOptions(productSelect, packageSelect, {
                        clearPackage: true
                    });
                });
            });

            document.querySelectorAll('[data-stock-key-input]').forEach((textarea) => {
                const resizeTextarea = () => {
                    const maxHeight = Number.parseInt(getComputedStyle(textarea).maxHeight, 10) || 240;

                    textarea.style.height = 'auto';
                    textarea.style.height = `${Math.min(textarea.scrollHeight, maxHeight)}px`;
                    textarea.style.overflowY = textarea.scrollHeight > maxHeight ? 'auto' : 'hidden';
                };

                textarea.addEventListener('input', resizeTextarea);
                resizeTextarea();
            });

            form?.addEventListener('submit', () => {
                form.querySelector('input[name="page"]')?.remove();
            });
        });
    </script>
@endsection
