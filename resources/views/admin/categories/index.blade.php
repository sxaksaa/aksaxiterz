@extends('layouts.app')

@section('content')
    @php
        $addingCategory = old('category_action') === 'create';
        $categoryQuery = request()->only(['search', 'page']);
        $iconFor = function (?string $slug, ?string $name = null) {
            $key = strtolower(trim($slug ?: ($name ?? '')));

            return match ($key) {
                'pc', 'desktop', 'windows' => 'monitor',
                'ios', 'iphone', 'ipad', 'macos' => 'apple',
                'android' => 'android',
                default => 'box',
            };
        };
    @endphp

    <div class="page-shell py-6 md:py-10">
        <section class="orders-hero fade-up mb-6">
            <div>
                <p class="mb-2 text-sm font-semibold text-aksa-accent">Admin</p>
                <h1 class="text-3xl font-bold tracking-normal md:text-4xl">Categories</h1>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                    Manage storefront categories used by catalog filters, product forms, and platform badges.
                </p>
            </div>

            <div class="admin-stat-grid mt-6 grid gap-3 sm:grid-cols-3">
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['categories'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Categories</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['used_categories'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Used by products</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['products'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Catalog products</div>
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
            <button type="button" class="btn-footer-secondary" data-catalog-panel-toggle aria-controls="categoryAddPanel" aria-expanded="{{ $addingCategory ? 'true' : 'false' }}">
                <x-ui.icon name="box" class="h-4 w-4" />
                <span>Add category</span>
                <x-ui.icon name="chevron-down" class="catalog-chevron h-4 w-4" />
            </button>
            <button type="button" class="btn-footer-secondary" data-catalog-panel-toggle aria-controls="categorySearchPanel" aria-expanded="false">
                <x-ui.icon name="search" class="h-4 w-4" />
                <span>Search{{ request()->filled('search') ? ' (active)' : '' }}</span>
                <x-ui.icon name="chevron-down" class="catalog-chevron h-4 w-4" />
            </button>
        </div>

        <div id="categoryAddPanel" class="catalog-disclosure-panel" @if (! $addingCategory) hidden @endif>
            <div class="catalog-disclosure-spacing">
                <section class="product-section">
                    <h2 class="mb-4 text-sm font-semibold text-white">Add category</h2>
                    <form action="{{ route('admin.categories.store') }}" method="POST" class="grid items-end gap-4 md:grid-cols-[1fr_1fr_auto]">
                        @csrf
                        <input type="hidden" name="category_action" value="create">
                        <label class="block text-xs text-gray-400">Name
                            <input name="name" value="{{ $addingCategory ? old('name') : '' }}" class="search-bar mt-2 w-full" placeholder="PC, Android, iOS" required maxlength="80">
                        </label>
                        <label class="block text-xs text-gray-400">Slug (optional)
                            <input name="slug" value="{{ $addingCategory ? old('slug') : '' }}" class="search-bar mt-2 w-full" placeholder="Generated from name if empty" maxlength="80">
                        </label>
                        <button type="submit" class="btn-footer h-12">Add category</button>
                    </form>
                </section>
            </div>
        </div>

        <div id="categorySearchPanel" class="catalog-disclosure-panel" hidden>
            <div class="catalog-disclosure-spacing">
                <section class="product-section">
                    <form method="GET" action="{{ route('admin.categories.index') }}" class="grid items-end gap-3 md:grid-cols-[1fr_auto]">
                        <label class="block text-xs text-gray-400">Search
                            <input name="search" value="{{ request('search') }}" class="search-bar mt-2 w-full" placeholder="Name or slug">
                        </label>
                        <div class="flex gap-2">
                            <button type="submit" class="btn-footer h-12">Search</button>
                            <a href="{{ route('admin.categories.index') }}" class="btn-footer-secondary h-12">Reset</a>
                        </div>
                    </form>
                </section>
            </div>
        </div>

        <section class="space-y-4">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-white">Category Records</h2>
                    <p class="mt-1 text-xs text-gray-500">Click a category name to manage it. Icons follow the category name/slug.</p>
                </div>
                <span class="text-xs text-aksa-accent">{{ $categories->total() }} records</span>
            </div>
            @forelse ($categories as $category)
                @php
                    $editingCategory = (string) old('edit_category_id') === (string) $category->id;
                    $categoryOpen = $editingCategory || (string) request('edit') === (string) $category->id
                        || (string) old('delete_category_id') === (string) $category->id;
                @endphp
                <details class="product-section catalog-accordion" data-catalog-accordion @if ($categoryOpen) open @endif>
                    <summary class="catalog-accordion-summary text-sm font-semibold text-white">
                        <span class="inline-flex items-center gap-2">
                            <x-ui.icon :name="$iconFor($category->slug, $category->name)" class="h-4 w-4" />
                            {{ $category->name }}
                        </span>
                        <x-ui.icon name="chevron-down" class="catalog-chevron h-4 w-4" />
                    </summary>
                    <p class="mt-3 text-xs text-gray-400">{{ $category->products_count }} {{ \Illuminate\Support\Str::plural('product', $category->products_count) }}</p>
                    <form data-catalog-edit-form action="{{ route('admin.categories.update', ['category' => $category, ...$categoryQuery]) }}" method="POST" class="mt-4 space-y-4">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="edit_category_id" value="{{ $category->id }}">
                        <fieldset data-catalog-fields class="grid gap-4 md:grid-cols-2" @disabled(! $editingCategory)>
                            <label class="block text-xs text-gray-400">Name
                                <input name="name" value="{{ $editingCategory ? old('name', $category->name) : $category->name }}" class="search-bar mt-2 w-full" required maxlength="80">
                            </label>
                            <label class="block text-xs text-gray-400">Slug (optional)
                                <input name="slug" value="{{ $editingCategory ? old('slug', $category->slug) : $category->slug }}" class="search-bar mt-2 w-full" maxlength="80" placeholder="Generated from name if empty">
                            </label>
                        </fieldset>
                        <button type="button" data-catalog-edit-button class="btn-footer">{{ $editingCategory ? 'Save' : 'Edit' }}</button>
                    </form>
                    <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-[#27272A] pt-4">
                        <p class="text-xs text-gray-500">{{ $category->products_count > 0 ? 'Move products to another category before deleting.' : 'This category has no products.' }}</p>
                        <form action="{{ route('admin.categories.destroy', ['category' => $category, ...$categoryQuery]) }}" method="POST" data-confirm="Delete this category?">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="delete_category_id" value="{{ $category->id }}">
                            <button type="submit" class="order-action order-action-danger disabled:opacity-50" @disabled($category->products_count > 0)>Delete category</button>
                        </form>
                    </div>
                </details>
            @empty
                <div class="empty-state">No categories found</div>
            @endforelse
        </section>

        @include('partials.pagination', [
            'paginator' => $categories,
            'label' => 'Categories pagination',
            'itemLabel' => 'categories',
        ])
    </div>
@endsection
