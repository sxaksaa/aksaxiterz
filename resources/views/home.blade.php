@extends('layouts.app')

@section('content')
    @php
        $discordUrl = config('links.discord_url');
        $downloadGroups = collect(config('links.downloads', []))->filter(fn ($download) => filled($download['name'] ?? null))->count();
        $totalStock = $products->sum('available_license_stocks_count');
    @endphp

    <section class="page-shell pb-6 pt-6 md:pt-10">
        <div class="home-hero fade-up">
            <div class="grid gap-6 lg:grid-cols-[1.25fr_0.75fr] lg:items-end">
                <div>
                    <p class="mb-2 text-sm font-semibold text-[#C084FC]">Digital License Platform</p>
                    <h1 class="text-3xl font-bold tracking-normal md:text-5xl">
                        Premium tools, instant licenses, and public setup files.
                    </h1>
                    <p class="mt-4 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                        Browse trusted digital tools, pay securely, and get quick access to licenses, downloads,
                        setup resources, and customer support.
                    </p>

                    <div class="mt-5 flex flex-wrap gap-3">
                        <a href="#products" class="btn-main">Browse Products</a>
                        <a href="/downloads" class="btn-footer-secondary">Download Tools</a>
                        <a href="{{ $discordUrl ?: '#' }}"
                            @if ($discordUrl) target="_blank" rel="noopener noreferrer" @endif
                            class="btn-footer {{ $discordUrl ? '' : 'cursor-not-allowed opacity-50' }}">
                            Discord
                        </a>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-1">
                    <div class="home-panel">
                        <div class="text-xl font-semibold text-white">Since 2024</div>
                        <div class="mt-1 text-xs text-gray-400">Serving customers with digital support.</div>
                    </div>
                    <div class="home-panel">
                        <div class="text-xl font-semibold text-white">{{ $downloadGroups }}</div>
                        <div class="mt-1 text-xs text-gray-400">Public download groups ready.</div>
                    </div>
                    <div class="home-panel">
                        <div class="text-xl font-semibold text-white">{{ $totalStock }}</div>
                        <div class="mt-1 text-xs text-gray-400">Available license stock.</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="products" class="page-shell pb-6 md:pb-10">
        @php $active = request('category'); @endphp

        <div class="home-toolbar fade-up">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Products</p>
                    <h2 class="mt-1 text-2xl font-semibold text-white">Find your tool</h2>
                    <p class="mt-1 text-sm text-gray-400">Search by product name or filter by category.</p>
                </div>

                <div class="w-full lg:max-w-md">
                    <input type="text" id="searchInput" placeholder="Search tools, products..."
                        class="search-bar w-full text-sm md:text-base" value="{{ request('search') }}">
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-2 md:gap-3">
                <a href="#" onclick="filterCategory('', this); return false;"
                    class="category-chip {{ !$active ? 'active' : '' }}">
                    All
                </a>

                @foreach ($categories as $category)
                    <a href="#" onclick="filterCategory('{{ $category->slug }}', this); return false;"
                        class="category-chip {{ $active == $category->slug ? 'active' : '' }}">
                        {{ $category->name }}
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    <div class="page-shell pb-16 md:pb-20">
        <div class="mb-5 flex flex-col gap-2 text-center md:text-left">
            <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Storefront</p>
            <h2 class="text-2xl font-semibold text-white">Available products</h2>
        </div>

        <div id="productContainer"
            class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6 transition-opacity duration-200">

            @include('partials.product-card', ['products' => $products])

        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {

            let timeout;
            let currentCategory = @json(request('category', ''));

            const searchInput = document.getElementById('searchInput');
            const container = document.getElementById('productContainer');

            if (!searchInput || !container) return;

            searchInput.addEventListener('input', function() {

                clearTimeout(timeout);

                timeout = setTimeout(() => {
                    fetchProducts(this.value, currentCategory);
                }, 200);

            });

            window.filterCategory = function(cat, el) {

                currentCategory = cat;
                const categoryName = el?.textContent?.trim() || 'All';

                document.querySelectorAll('.category-chip')
                    .forEach(e => e.classList.remove('active'));

                el.classList.add('active');

                fetchProducts(searchInput.value, cat);

                window.showAppToast?.(
                    'Category selected',
                    categoryName === 'All' ? 'Showing all products.' : `Showing ${categoryName}.`, {
                        variant: 'success'
                    }
                );
            }

            function fetchProducts(search, category) {

                const params = new URLSearchParams({
                    search,
                    category,
                });

                fetch(`/api/products?${params.toString()}`)
                    .then(res => res.text())
                    .then(html => {
                        container.innerHTML = html;
                    });
            }

        });
    </script>
@endsection
