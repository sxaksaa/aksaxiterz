@extends('layouts.app')

@section('content')
    @php
        $isEditing = (bool) $editCategory;
        $formAction = $isEditing
            ? route('admin.categories.update', $editCategory)
            : route('admin.categories.store');
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

            <div class="mt-6 grid gap-3 sm:grid-cols-3">
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

        <section class="product-section mb-6 fade-up">
            <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">
                        {{ $isEditing ? 'Edit Category' : 'New Category' }}
                    </p>
                    <h2 class="mt-1 text-xl font-semibold text-white">
                        {{ $isEditing ? $editCategory->name : 'Add Category' }}
                    </h2>
                </div>

                @if ($isEditing)
                    <a href="{{ route('admin.categories.index') }}" class="btn-footer-secondary w-fit">
                        <x-ui.icon name="x" class="h-4 w-4" />
                        <span>Cancel Edit</span>
                    </a>
                @endif
            </div>

            <form action="{{ $formAction }}" method="POST" class="grid gap-4 lg:grid-cols-[1fr_1fr_auto] lg:items-end">
                @csrf
                @if ($isEditing)
                    @method('PATCH')
                @endif

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Name</span>
                    <input name="name" value="{{ old('name', $editCategory->name ?? '') }}" class="search-bar w-full"
                        placeholder="PC, Android, iOS" required maxlength="80">
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Slug</span>
                    <input name="slug" value="{{ old('slug', $editCategory->slug ?? '') }}" class="search-bar w-full"
                        placeholder="pc-android-ios" maxlength="80">
                </label>

                <button class="btn-footer h-12">
                    <x-ui.icon name="{{ $isEditing ? 'save' : 'box' }}" class="h-4 w-4" />
                    <span>{{ $isEditing ? 'Save Category' : 'Add Category' }}</span>
                </button>
            </form>
        </section>

        <section class="product-section mb-6 fade-up">
            <form method="GET" action="{{ route('admin.categories.index') }}"
                class="grid gap-3 md:grid-cols-[1fr_auto] md:items-end">
                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Search</span>
                    <input name="search" value="{{ request('search') }}" class="search-bar w-full"
                        placeholder="Name or slug">
                </label>

                <div class="flex gap-2">
                    <button class="btn-footer h-12">
                        <x-ui.icon name="filter" class="h-4 w-4" />
                        <span>Filter</span>
                    </button>
                    <a href="{{ route('admin.categories.index') }}" class="btn-footer-secondary h-12">
                        <x-ui.icon name="rotate-ccw" class="h-4 w-4" />
                        <span>Reset</span>
                    </a>
                </div>
            </form>
        </section>

        <div class="orders-table-wrap hidden lg:block">
            <div class="flex items-center justify-between gap-3 border-b border-[#27272A] px-4 py-4">
                <div>
                    <h2 class="text-sm font-semibold text-white">Category Records</h2>
                    <p class="mt-1 text-xs text-gray-500">Icons are inferred from slug/name for PC, Android, and iOS.</p>
                </div>
                <span class="rounded-lg border border-aksa-accent-30 bg-aksa-accent-10 px-3 py-1 text-xs font-semibold text-aksa-accent">
                    {{ $categories->total() }} records
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="bg-[#111115] text-xs uppercase tracking-normal text-gray-500">
                        <tr>
                            <th class="p-4 text-left">Category</th>
                            <th class="p-4 text-left">Slug</th>
                            <th class="p-4 text-left">Products</th>
                            <th class="p-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($categories as $category)
                            <tr class="orders-table-row">
                                <td class="p-4">
                                    <div class="inline-flex items-center gap-2">
                                        <span class="product-category-pill">
                                            <x-ui.icon :name="$iconFor($category->slug, $category->name)" class="h-4 w-4" />
                                            <span>{{ $category->name }}</span>
                                        </span>
                                    </div>
                                </td>
                                <td class="p-4">
                                    <span class="font-mono text-xs text-gray-300">{{ $category->slug }}</span>
                                </td>
                                <td class="p-4 text-gray-300">
                                    {{ $category->products_count }} {{ \Illuminate\Support\Str::plural('product', $category->products_count) }}
                                </td>
                                <td class="p-4 text-right">
                                    <div class="inline-flex justify-end gap-2">
                                        <a href="{{ route('admin.categories.index', array_merge(request()->query(), ['edit' => $category->id])) }}"
                                            class="order-action">
                                            <x-ui.icon name="edit-3" class="h-4 w-4" />
                                            <span>Edit</span>
                                        </a>
                                        <form action="{{ route('admin.categories.destroy', $category) }}" method="POST"
                                            data-confirm="Delete this category?">
                                            @csrf
                                            @method('DELETE')
                                            <button class="order-action order-action-danger" @disabled($category->products_count > 0)>
                                                <x-ui.icon name="trash-2" class="h-4 w-4" />
                                                <span>Delete</span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="p-8">
                                    <div class="empty-state">No categories found</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-4 lg:hidden">
            @forelse ($categories as $category)
                <article class="order-mobile-card motion-card">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <span class="product-category-pill">
                                <x-ui.icon :name="$iconFor($category->slug, $category->name)" class="h-4 w-4" />
                                <span>{{ $category->name }}</span>
                            </span>
                            <div class="mt-2 font-mono text-xs text-gray-500">{{ $category->slug }}</div>
                        </div>
                        <span class="status-pill status-pill-paid">
                            {{ $category->products_count }} products
                        </span>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <a href="{{ route('admin.categories.index', array_merge(request()->query(), ['edit' => $category->id])) }}"
                            class="order-action">
                            <x-ui.icon name="edit-3" class="h-4 w-4" />
                            <span>Edit</span>
                        </a>
                        <form action="{{ route('admin.categories.destroy', $category) }}" method="POST"
                            data-confirm="Delete this category?">
                            @csrf
                            @method('DELETE')
                            <button class="order-action order-action-danger" @disabled($category->products_count > 0)>
                                <x-ui.icon name="trash-2" class="h-4 w-4" />
                                <span>Delete</span>
                            </button>
                        </form>
                    </div>
                </article>
            @empty
                <div class="empty-state">No categories found</div>
            @endforelse
        </div>

        @include('partials.pagination', [
            'paginator' => $categories,
            'label' => 'Categories pagination',
            'itemLabel' => 'categories',
        ])
    </div>
@endsection
