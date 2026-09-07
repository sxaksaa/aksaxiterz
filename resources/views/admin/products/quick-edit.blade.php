@php
    $editing = (string) old('quick_product_id') === (string) $product->id;
    $value = fn ($field, $default) => $editing ? old($field, $default) : $default;
    $catalogQuery = request()->only(['search', 'category_id', 'visibility', 'page']);
    $existingNames = $product->packages->pluck('name')->all();
    $addingPackage = (string) old('add_package_product_id') === (string) $product->id;
    $canDeleteProduct = $product->orders_count === 0 && $product->order_items_count === 0 && $product->license_stocks_count === 0;
@endphp
<details class="product-section catalog-accordion" data-catalog-accordion @if ($editing || $addingPackage) open @endif>
    <summary class="catalog-accordion-summary text-sm font-semibold text-white">
        <span>{{ $product->name }}</span>
        <x-ui.icon name="chevron-down" class="catalog-chevron h-4 w-4" />
    </summary>
    <p class="mt-3 text-xs text-gray-400">{{ $product->available_license_stocks_count }} available keys / {{ $product->license_stocks_count }} total keys</p>
    <form data-catalog-edit-form method="POST" action="{{ route('admin.products.quick-update', ['product' => $product, ...request()->only(['search', 'category_id', 'visibility', 'page'])]) }}" class="mt-4 space-y-4">
        @csrf
        @method('PATCH')
        <input type="hidden" name="quick_product_id" value="{{ $product->id }}">
        <fieldset data-catalog-fields class="space-y-4" @disabled(! $editing)>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <label class="block text-xs text-gray-400">Product name
                    <input name="name" value="{{ $value('name', $product->name) }}" required maxlength="120" class="search-bar mt-2 w-full">
                </label>
                <label class="block text-xs text-gray-400">Category
                    <select name="category_id" required class="search-bar mt-2 w-full">
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) $value('category_id', $product->category_id) === (string) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-xs text-gray-400">Status
                    <select name="status" required class="search-bar mt-2 w-full">
                        @foreach ($statusOptions as $key => $label)
                            <option value="{{ $key }}" @selected($value('status', $product->status) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-xs text-gray-400">Visibility
                    <select name="is_visible" required class="search-bar mt-2 w-full">
                        <option value="1" @selected((string) $value('is_visible', (int) $product->is_visible) === '1')>Public</option>
                        <option value="0" @selected((string) $value('is_visible', (int) $product->is_visible) === '0')>Hidden</option>
                    </select>
                </label>
            </div>
            <label class="block text-xs text-gray-400">Description
                <textarea name="description" required maxlength="1000" rows="2" class="search-bar mt-2 w-full">{{ $value('description', $product->description) }}</textarea>
            </label>
            <label class="block text-xs text-gray-400">Important note (optional)
                <textarea name="important_note" maxlength="5000" rows="3" class="search-bar mt-2 w-full" placeholder="Note shown to customers. Leave empty to hide it.">{{ $value('important_note', $product->important_note) }}</textarea>
            </label>
            <fieldset class="space-y-3">
                <legend class="mb-2 text-sm font-semibold text-white">Package prices</legend>
                @forelse ($product->packages as $package)
                    <div class="grid items-end gap-3 rounded-lg border border-[#27272A] p-3 sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <label class="block text-xs text-gray-400">Duration
                                <select name="packages[{{ $package->id }}][name]" required class="search-bar mt-2 w-full">
                                    @foreach ($packageOptions[$product->id] as $option)
                                        <option value="{{ $option }}" @selected($value('packages.'.$package->id.'.name', $package->name) === $option) @disabled(in_array($option, $existingNames, true) && $option !== $package->name)>{{ $option }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <div class="mt-1 text-xs text-gray-400">{{ $package->available_license_stocks_count }} available keys</div>
                        </div>
                        <input type="hidden" name="packages[{{ $package->id }}][id]" value="{{ $package->id }}">
                        <label class="block text-xs text-gray-400">IDR
                            <input type="number" name="packages[{{ $package->id }}][price]" value="{{ $value('packages.'.$package->id.'.price', $package->price) }}" min="0" max="999999999" step="1" required class="search-bar mt-2 w-full">
                        </label>
                        <label class="block text-xs text-gray-400">USDT (optional)
                            <input type="number" name="packages[{{ $package->id }}][price_usdt]" value="{{ $value('packages.'.$package->id.'.price_usdt', $package->price_usdt) }}" min="0" max="999999.9999" step="0.0001" class="search-bar mt-2 w-full">
                        </label>
                        <div>
                            @if ($package->orders_count === 0 && $package->order_items_count === 0 && $package->license_stocks_count === 0)
                                <button type="submit" form="delete-package-{{ $package->id }}" class="order-action order-action-danger">Delete package</button>
                            @else
                                <span class="text-xs text-gray-500">Deletion locked by stock/order history</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-xs text-gray-400">No packages yet. Add one below.</p>
                @endforelse
            </fieldset>
        </fieldset>
        <div>
            <button type="button" data-catalog-edit-button class="btn-footer">{{ $editing ? 'Save' : 'Edit' }}</button>
        </div>
    </form>
    @foreach ($product->packages as $package)
        <form id="delete-package-{{ $package->id }}" action="{{ route('admin.packages.destroy', ['package' => $package, ...$catalogQuery]) }}" method="POST" data-confirm="Delete this package?">
            @csrf
            @method('DELETE')
            <input type="hidden" name="from_catalog" value="1">
        </form>
    @endforeach
    <div class="mt-5 border-t border-[#27272A] pt-4">
        <h4 class="mb-3 text-sm font-semibold text-white">Add package</h4>
        <form action="{{ route('admin.products.packages.store', ['product' => $product, ...$catalogQuery]) }}" method="POST" class="grid items-end gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @csrf
            <input type="hidden" name="from_catalog" value="1">
            <input type="hidden" name="add_package_product_id" value="{{ $product->id }}">
            <label class="block text-xs text-gray-400">Duration
                <select name="package_name" required class="search-bar mt-2 w-full">
                    <option value="">Select duration</option>
                    @foreach ($packageOptions[$product->id] as $option)
                        <option value="{{ $option }}" @selected($addingPackage && old('package_name') === $option) @disabled(in_array($option, $existingNames, true))>{{ $option }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-xs text-gray-400">IDR
                <input type="number" name="package_price" value="{{ $addingPackage ? old('package_price') : '' }}" min="0" max="999999999" step="1" required class="search-bar mt-2 w-full">
            </label>
            <label class="block text-xs text-gray-400">USDT (optional)
                <input type="number" name="package_price_usdt" value="{{ $addingPackage ? old('package_price_usdt') : '' }}" min="0" max="999999.9999" step="0.0001" class="search-bar mt-2 w-full">
            </label>
            <div><button type="submit" class="btn-footer">Add package</button></div>
        </form>
    </div>
    <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-[#27272A] pt-4">
        <p class="text-xs text-gray-500">{{ $canDeleteProduct ? 'No orders or license stock.' : 'Product deletion locked by stock/order history.' }}</p>
        <form action="{{ route('admin.products.destroy', ['product' => $product, ...$catalogQuery]) }}" method="POST" data-confirm="Delete this product?">
            @csrf
            @method('DELETE')
            <button type="submit" class="order-action order-action-danger disabled:opacity-50" @disabled(! $canDeleteProduct)>Delete product</button>
        </form>
    </div>
</details>
