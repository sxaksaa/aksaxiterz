@extends('layouts.app')

@section('seo_title', 'Aksa Xiterz - Digital Game Licenses')
@section('seo_description', 'Browse ready-stock digital game licenses with clear prices, automatic delivery, and secure payment verification.')

@section('content')
    <section class="page-shell pb-7 pt-7 md:pb-10 md:pt-11">
        <div class="home-hero home-hero-compact fade-up">
            <h1 class="hero-title">
                <span class="block">Sharpen your aim.</span>
                <span class="hero-accent block">Elevate your gameplay.</span>
            </h1>

            <div class="hero-actions">
                <a href="#products" class="btn-main px-5 py-3">
                    <x-ui.icon name="boxes" class="h-4 w-4" />
                    <span>Explore Products</span>
                </a>
            </div>

            <div class="home-proof-strip">
                <span><strong>5000+</strong> licenses delivered</span>
                <span><strong>2000+</strong> community members</span>
                <span><strong data-total-ready-stock>{{ $totalStock }}</strong> ready stock</span>
            </div>
        </div>
    </section>

    @if ($promoVoucher ?? null)
        <section class="page-shell pb-6">
            @include('partials.promo-banner', ['promoVoucher' => $promoVoucher])
        </section>
    @endif

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
                    <h2 class="mt-1 text-2xl font-semibold text-white">Find your tool</h2>
                    <p class="mt-1 text-sm text-gray-400">Search by product name or filter by category.</p>
                </div>

                <div class="w-full">
                    <input type="text" id="searchInput" placeholder="Search Products..."
                        class="search-bar w-full text-sm md:text-base" value="{{ request('search') }}">
                </div>
            </div>

            <div class="category-filter-row mt-4 flex gap-2 md:gap-3" aria-label="Product categories">
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

        <div id="productContainer"
            class="mt-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6 transition-opacity duration-200">

            @include('partials.product-card', ['products' => $products])

        </div>
    </section>

    @include('partials.recent-purchase-toast', [
        'recentPurchases' => $recentPurchases ?? collect(),
        'recentPurchaseEndpoint' => route('purchases.recent', [], false),
    ])

    <script nonce="{{ request()->attributes->get('csp_nonce') }}">
        document.addEventListener('DOMContentLoaded', () => {

            let currentCategory = @json(request('category', ''));
            const productEndpoint = @json(route('products.fragment', [], false));
            const productStockEndpoint = @json(route('products.stocks', [], false));
            const stockPollingInterval = 30000;

            const searchInput = document.getElementById('searchInput');
            const container = document.getElementById('productContainer');
            const totalStock = document.querySelector('[data-total-ready-stock]');
            let searchTimeout = null;
            let productRequestController = null;
            let productRequestSequence = 0;
            let stockRequestController = null;
            let stockPollingTimer = null;
            let stockPollingDisposed = false;

            if (!searchInput || !container) return;

            searchInput.addEventListener('input', function() {

                clearTimeout(searchTimeout);

                searchTimeout = setTimeout(() => {
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

                const requestSequence = ++productRequestSequence;
                productRequestController?.abort();
                stockRequestController?.abort();
                const controller = new AbortController();
                productRequestController = controller;

                const params = new URLSearchParams({
                    search,
                    category,
                });

                container.classList.add('product-container-loading');
                container.innerHTML = productSkeletonHtml();

                fetch(`${productEndpoint}?${params.toString()}`, {
                        cache: 'no-store',
                        headers: {
                            'Accept': 'text/html',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        signal: controller.signal,
                    })
                    .then(res => {
                        if (!res.ok) {
                            throw new Error(`Product filter failed with status ${res.status}`);
                        }

                        return res.text();
                    })
                    .then(html => {
                        if (requestSequence !== productRequestSequence) return;

                        container.innerHTML = html.trim() || emptyProductsHtml();
                        window.refreshAksaDisplayCurrency?.(container);
                        refreshProductStocks();
                    })
                    .catch(error => {
                        if (error.name === 'AbortError' || requestSequence !== productRequestSequence) return;

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
                        if (productRequestController === controller) {
                            productRequestController = null;
                            container.classList.remove('product-container-loading');
                        }
                    });
            }

            function applyProductStockSnapshot(snapshot) {
                if (!snapshot || !Array.isArray(snapshot.products)) return;

                snapshot.products.forEach((product) => {
                    const productId = Number(product.id);
                    const stock = Number(product.available_stock);

                    if (!Number.isSafeInteger(productId) || productId <= 0 || !Number.isSafeInteger(stock) || stock < 0) {
                        return;
                    }

                    const card = container.querySelector(`[data-product-stock-card][data-product-id="${productId}"]`);

                    if (!card) return;

                    const status = product.status === 'updating' ? 'updating' : 'ready';
                    const isUpdating = status === 'updating';
                    const hasReadyStock = !isUpdating && stock > 0;
                    const statusBadge = card.querySelector('[data-product-status-badge]');

                    card.dataset.productStatus = status;
                    card.dataset.productStock = String(stock);

                    if (statusBadge) {
                        statusBadge.textContent = product.status_label || (isUpdating ? 'Updating' : 'Ready');
                        statusBadge.classList.toggle('product-status-badge-updating', isUpdating);
                        statusBadge.classList.toggle('product-status-badge-ready', !isUpdating);
                    }

                    card.querySelector('[data-product-stock-icon-ready]')
                        ?.classList.toggle('hidden', !hasReadyStock);
                    card.querySelector('[data-product-stock-icon-unavailable]')
                        ?.classList.toggle('hidden', hasReadyStock);

                    const stockLabel = card.querySelector('[data-product-stock-label]');

                    if (stockLabel) {
                        stockLabel.textContent = isUpdating
                            ? 'Update alerts in Discord'
                            : (stock > 0 ? `${stock} ready` : 'Manual order');
                    }
                });

                const totalAvailableStock = Number(snapshot.total_available_stock);

                if (totalStock && Number.isSafeInteger(totalAvailableStock) && totalAvailableStock >= 0) {
                    totalStock.textContent = String(totalAvailableStock);
                }
            }

            async function refreshProductStocks() {
                if (stockPollingDisposed || document.hidden || stockRequestController) return;

                const controller = new AbortController();
                stockRequestController = controller;

                try {
                    const response = await fetch(productStockEndpoint, {
                        cache: 'no-store',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        signal: controller.signal,
                    });

                    if (!response.ok) {
                        throw new Error(`Stock refresh failed with status ${response.status}`);
                    }

                    const snapshot = await response.json();

                    if (!controller.signal.aborted && !stockPollingDisposed) {
                        applyProductStockSnapshot(snapshot);
                    }
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        // Stock polling is best-effort; checkout still validates availability on the server.
                    }
                } finally {
                    if (stockRequestController === controller) {
                        stockRequestController = null;
                    }
                }
            }

            function clearStockPollingTimer() {
                if (stockPollingTimer) {
                    clearTimeout(stockPollingTimer);
                    stockPollingTimer = null;
                }
            }

            function pauseStockPolling() {
                clearStockPollingTimer();

                const controller = stockRequestController;
                controller?.abort();

                if (stockRequestController === controller) {
                    stockRequestController = null;
                }
            }

            function scheduleStockPolling(delay = stockPollingInterval) {
                clearStockPollingTimer();

                if (stockPollingDisposed || document.hidden) return;

                stockPollingTimer = setTimeout(async () => {
                    stockPollingTimer = null;
                    await refreshProductStocks();
                    scheduleStockPolling();
                }, delay);
            }

            async function resumeStockPolling() {
                if (stockPollingDisposed || document.hidden) return;

                clearStockPollingTimer();
                await refreshProductStocks();
                scheduleStockPolling();
            }

            function disposeHomePage() {
                stockPollingDisposed = true;
                clearTimeout(searchTimeout);
                productRequestSequence++;
                productRequestController?.abort();
                productRequestController = null;
                pauseStockPolling();
            }

            scheduleStockPolling();

            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    pauseStockPolling();
                } else {
                    resumeStockPolling();
                }
            });

            window.addEventListener('pagehide', pauseStockPolling);

            window.addEventListener('pageshow', (event) => {
                if (event.persisted) {
                    resumeStockPolling();
                }
            });

            window.addEventListener('aksa:before-page-swap', disposeHomePage, {
                once: true,
            });

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
