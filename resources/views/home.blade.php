@extends('layouts.app')

@section('content')
    @php
        $totalStock = $products->sum('available_license_stocks_count');
    @endphp

    <section class="page-shell pb-6 pt-6 md:pt-10">
        <div class="home-hero fade-up">
            <div class="grid gap-6 lg:grid-cols-[1.25fr_0.75fr] lg:items-center">
                <div>
                    <p class="mb-2 text-sm font-semibold text-[#C084FC]">Digital License Platform</p>
                    <h1 class="text-3xl font-bold tracking-normal md:text-5xl">
                        Premium tools and instant digital licenses.
                    </h1>
                    <p class="mt-4 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                        Browse trusted digital tools, pay securely, and get quick access to license keys, setup
                        guides, and customer support.
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-1">
                    <div class="home-panel">
                        <div class="text-xl font-semibold text-white">5000+ Licenses</div>
                        <div class="mt-1 text-xs text-gray-400">Delivered to customers with support.</div>
                    </div>
                    <div class="home-panel">
                        <div class="text-xl font-semibold text-white">2000+ Members</div>
                        <div class="mt-1 text-xs text-gray-400">Active community on Discord.</div>
                    </div>
                    <div class="home-panel">
                        <div class="text-xl font-semibold text-white">Since 2024</div>
                        <div class="mt-1 text-xs text-gray-400">{{ $totalStock }} licenses ready for auto delivery.</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="products" class="page-shell pb-6 md:pb-10">
        @php
            $active = request('category');
            $categoryIcon = function (?string $slug, ?string $name = null) {
                $key = strtolower(trim($slug ?: ($name ?? '')));

                return match ($key) {
                    'pc', 'desktop', 'windows' => 'monitor',
                    'ios', 'iphone', 'ipad', 'macos' => 'apple',
                    'android' => 'android',
                    default => 'box',
                };
            };
        @endphp

        <div class="home-toolbar fade-up">
            <div class="flex flex-col gap-4 lg:grid lg:grid-cols-[1.25fr_0.75fr] lg:items-end lg:gap-6">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Products</p>
                    <h2 class="mt-1 text-2xl font-semibold text-white">Find your tool</h2>
                    <p class="mt-1 text-sm text-gray-400">Search by product name or filter by category.</p>
                </div>

                <div class="w-full">
                    <input type="text" id="searchInput" placeholder="Search Products..."
                        class="search-bar w-full text-sm md:text-base" value="{{ request('search') }}">
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-2 md:gap-3">
                <a href="#" data-category-filter data-category=""
                    class="category-chip {{ !$active ? 'active' : '' }}">
                    <x-ui.icon name="boxes" class="h-4 w-4" />
                    <span>All</span>
                </a>

                @foreach ($categories as $category)
                    <a href="#" data-category-filter data-category="{{ $category->slug }}"
                        class="category-chip {{ $active == $category->slug ? 'active' : '' }}">
                        <x-ui.icon :name="$categoryIcon($category->slug, $category->name)" class="h-4 w-4" />
                        <span>{{ $category->name }}</span>
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

    <script nonce="{{ request()->attributes->get('csp_nonce') }}">
        document.addEventListener('DOMContentLoaded', () => {

            let timeout;
            let currentCategory = @json(request('category', ''));
            const productEndpoint = @json(route('products.fragment', [], false));

            const searchInput = document.getElementById('searchInput');
            const container = document.getElementById('productContainer');

            if (!searchInput || !container) return;

            searchInput.addEventListener('input', function() {

                clearTimeout(timeout);

                timeout = setTimeout(() => {
                    fetchProducts(this.value, currentCategory);
                }, 200);

            });

            document.querySelectorAll('[data-category-filter]').forEach((chip) => {
                chip.addEventListener('click', (event) => {
                    event.preventDefault();
                    filterCategory(chip.dataset.category || '', chip);
                });
            });

            function filterCategory(cat, el) {

                currentCategory = cat;
                const categoryName = el && el.textContent ? el.textContent.trim() : 'All';

                document.querySelectorAll('.category-chip')
                    .forEach(e => e.classList.remove('active'));

                if (el) {
                    el.classList.add('active');
                }

                fetchProducts(searchInput.value, cat);

                if (window.showAppToast) {
                    window.showAppToast(
                        'Category selected',
                        categoryName === 'All' ? 'Showing all products.' : `Showing ${categoryName}.`, {
                        variant: 'success'
                        }
                    );
                }
            }

            function fetchProducts(search, category) {

                const params = new URLSearchParams({
                    search,
                    category,
                });

                container.classList.add('product-container-loading');
                container.innerHTML = productSkeletonHtml();

                fetch(`${productEndpoint}?${params.toString()}`, {
                        headers: {
                            'Accept': 'text/html',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    })
                    .then(res => {
                        if (!res.ok) {
                            throw new Error(`Product filter failed with status ${res.status}`);
                        }

                        return res.text();
                    })
                    .then(html => {
                        container.innerHTML = html.trim() || emptyProductsHtml();
                    })
                    .catch(error => {
                        container.innerHTML = emptyProductsHtml(
                            'Products could not be loaded. Please refresh the page and try again.'
                        );

                        if (window.showAppToast) {
                            window.showAppToast('Products not loaded', error.message, {
                                variant: 'error'
                            });
                        }
                    })
                    .finally(() => {
                        container.classList.remove('product-container-loading');
                    });
            }

            function emptyProductsHtml(message = 'No products match this filter yet.') {
                return `
                    <div class="empty-state sm:col-span-2 lg:col-span-3">
                        <span class="empty-state-icon">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="m21 8-9-5-9 5 9 5 9-5Z"></path>
                                <path d="M3 8v8l9 5 9-5V8"></path>
                                <path d="M12 13v8"></path>
                            </svg>
                        </span>
                        <span class="empty-state-title">No products found</span>
                        <p class="empty-state-copy">${escapeHtml(message)}</p>
                    </div>
                `;
            }

            function productSkeletonHtml() {
                return Array.from({ length: 6 }, () => `
                    <div class="skeleton-card">
                        <span class="skeleton-line w-2/5"></span>
                        <span class="skeleton-line mt-5 w-4/5"></span>
                        <span class="skeleton-line mt-3 w-3/5"></span>
                        <span class="skeleton-line mt-8 w-full"></span>
                        <span class="skeleton-line mt-3 w-2/3"></span>
                    </div>
                `).join('');
            }

            function escapeHtml(value) {
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

        });
    </script>
@endsection
