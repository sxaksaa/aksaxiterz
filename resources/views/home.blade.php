@extends('layouts.app')

@section('seo_title', 'Aksa Xiterz - Digital Game Licenses')
@section('seo_description', 'Browse available digital game licenses with clear prices, automatic delivery, and secure payment verification.')

@section('content')
    <section class="page-shell pb-7 pt-7 md:pb-10 md:pt-11">
        <div class="home-hero home-hero-compact">
            <h1 class="hero-title" data-home-reveal-item data-home-reveal-stage="hero-title">
                <span class="block">Sharpen your aim.</span>
                <span class="hero-accent block">Elevate your gameplay.</span>
            </h1>

            <div class="hero-actions" data-home-reveal-item data-home-reveal-stage="hero-action">
                <a href="#products" class="btn-main px-5 py-3">
                    <x-ui.icon name="boxes" class="h-4 w-4" />
                    <span>Explore Products</span>
                </a>
            </div>

            <div class="home-proof-strip" data-home-reveal-item data-home-reveal-stage="proof">
                <span><strong data-home-count-up="5000" data-home-count-up-suffix="+">5000+</strong> licenses delivered</span>
                <span><strong data-home-count-up="2000" data-home-count-up-suffix="+">2000+</strong> community members</span>
                <span><strong data-home-count-up="{{ $totalStock }}" data-total-ready-stock>{{ $totalStock }}</strong> available licenses</span>
            </div>
        </div>
    </section>

    @if ($promoVoucher ?? null)
        <section class="page-shell pb-6" data-home-reveal-item data-home-reveal-stage="promo">
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

        <div class="home-toolbar">
            <div class="flex flex-col gap-4 lg:grid lg:grid-cols-[1.25fr_0.75fr] lg:items-end lg:gap-6">
                <div data-home-reveal-item data-home-reveal-stage="tools">
                    <h2 class="mt-1 text-2xl font-semibold text-white">Find your tool</h2>
                    <p class="mt-1 text-sm text-gray-400">Search by product name or filter by category.</p>
                </div>

                <div class="w-full" data-home-reveal-item data-home-reveal-stage="search">
                    <label for="searchInput" class="sr-only">Search products</label>
                    <div class="product-search-shell">
                        <input type="text" id="searchInput" placeholder="Search Products..."
                            autocomplete="off" inputmode="search"
                            class="search-bar w-full pr-11 text-sm md:text-base" value="{{ request('search') }}">
                        <button type="button" class="product-search-clear hidden" data-clear-product-search
                            aria-label="Clear product search">
                            <x-ui.icon name="x" class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </div>

            <div class="category-filter-row mt-4 flex gap-2 md:gap-3" role="group" aria-label="Product categories"
                data-category-filter-row data-home-reveal-item data-home-reveal-stage="tools">
                <span class="category-filter-glider" data-category-filter-glider aria-hidden="true"></span>

                <button type="button" data-category-filter data-category="" aria-pressed="{{ !$active ? 'true' : 'false' }}"
                    class="category-chip {{ !$active ? 'active' : '' }}">
                    <x-ui.icon name="boxes" class="h-4 w-4" />
                    <span>All</span>
                </button>

                @foreach ($categories as $category)
                    <button type="button" data-category-filter data-category="{{ $category->slug }}"
                        aria-pressed="{{ $active == $category->slug ? 'true' : 'false' }}"
                        class="category-chip {{ $active == $category->slug ? 'active' : '' }}">
                        <x-ui.icon :name="$categoryIcon($category->slug, $category->name)" class="h-4 w-4" />
                        <span>{{ $category->name }}</span>
                    </button>
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
        (() => {

            const pageContent = document.querySelector('[data-aksa-page-content]');
            const initialSearch = @json((string) request('search', ''));
            const initialCategory = @json((string) request('category', ''));
            const handedOffSearch = pageContent?.dataset.aksaRestoredHomeSearch;
            const handedOffCategory = pageContent?.dataset.aksaRestoredHomeCategory;
            const restoredHomeView = handedOffSearch !== undefined || handedOffCategory !== undefined
                ? {
                    search: handedOffSearch || '',
                    category: handedOffCategory || '',
                }
                : window.history.state?.aksaHomeView;
            const restoredScrollY = Number(
                pageContent?.dataset.aksaRestoredScrollY
                    ?? window.history.state?.aksaScrollPosition?.y
            );
            let currentCategory = initialCategory;
            const productEndpoint = @json(route('products.fragment', [], false));
            const productStockEndpoint = @json(route('products.stocks', [], false));
            const stockPollingInterval = 30000;

            const searchInput = document.getElementById('searchInput');
            const clearSearchButton = document.querySelector('[data-clear-product-search]');
            const container = document.getElementById('productContainer');
            const totalStock = document.querySelector('[data-total-ready-stock]');
            let searchTimeout = null;
            let productRequestController = null;
            let productRequestSequence = 0;
            let stockRequestController = null;
            let stockPollingTimer = null;
            let stockPollingDisposed = false;

            if (!searchInput || !container) return;

            function updateSearchClearButton() {
                clearSearchButton?.classList.toggle('hidden', searchInput.value.length === 0);
            }

            pageContent?.removeAttribute('data-aksa-restored-home-search');
            pageContent?.removeAttribute('data-aksa-restored-home-category');
            pageContent?.removeAttribute('data-aksa-restored-scroll-y');

            function categoryChipFor(category) {
                return [...document.querySelectorAll('[data-category-filter]')]
                    .find((chip) => (chip.dataset.category || '') === category) || null;
            }

            function selectCategoryChip(category) {
                const selectedChip = categoryChipFor(category) || categoryChipFor('');
                currentCategory = selectedChip?.dataset.category || '';

                document.querySelectorAll('.category-chip').forEach((chip) => {
                    const active = chip === selectedChip;
                    chip.classList.toggle('active', active);
                    chip.setAttribute('aria-pressed', active ? 'true' : 'false');
                });

                window.updateAksaCategoryGlider?.(selectedChip);

                return selectedChip;
            }

            function persistHomeViewState() {
                window.history.replaceState({
                    ...(window.history.state || {}),
                    aksaSoftNavigation: true,
                    aksaHomeView: {
                        search: searchInput.value,
                        category: currentCategory,
                    },
                }, '', window.location.href);
            }

            const restoredSearch = typeof restoredHomeView?.search === 'string'
                ? restoredHomeView.search.slice(0, 200)
                : initialSearch;
            const restoredCategory = typeof restoredHomeView?.category === 'string'
                ? restoredHomeView.category
                : initialCategory;

            searchInput.value = restoredSearch;
            updateSearchClearButton();
            selectCategoryChip(restoredCategory);

            searchInput.addEventListener('input', function() {

                clearTimeout(searchTimeout);
                updateSearchClearButton();

                searchTimeout = setTimeout(() => {
                    persistHomeViewState();
                    fetchProducts(this.value, currentCategory);
                }, 200);

            });

            clearSearchButton?.addEventListener('click', () => {
                if (!searchInput.value) return;

                clearTimeout(searchTimeout);
                searchInput.value = '';
                updateSearchClearButton();
                container.dataset.productRestore = 'true';
                persistHomeViewState();
                fetchProducts('', currentCategory);
                searchInput.focus();
            });

            document.querySelectorAll('[data-category-filter]').forEach((chip) => {
                chip.addEventListener('click', (event) => {
                    event.preventDefault();
                    filterCategory(chip.dataset.category || '', chip);
                });
            });

            container.addEventListener('click', (event) => {
                const clearButton = event.target.closest('[data-clear-product-filters]');
                if (!clearButton) return;

                searchInput.value = '';
                updateSearchClearButton();
                container.dataset.productRestore = 'true';
                const allCategory = document.querySelector('[data-category-filter][data-category=""]');
                filterCategory('', allCategory);
                searchInput.focus();
            });

            function filterCategory(cat, el) {

                const categoryName = el && el.textContent ? el.textContent.trim() : 'All';
                selectCategoryChip(cat);
                persistHomeViewState();

                fetchProducts(searchInput.value, currentCategory);

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

                const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                container.classList.remove('product-filter-entering');
                container.classList.add('product-filter-leaving');
                const exitDelay = reduceMotion ? 0 : 260;
                const skeletonTimer = setTimeout(() => {
                    if (requestSequence !== productRequestSequence) return;
                    container.classList.add('product-container-loading');
                    container.classList.remove('product-filter-leaving');
                    container.innerHTML = productSkeletonHtml();
                }, exitDelay);

                return fetch(`${productEndpoint}?${params.toString()}`, {
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

                        clearTimeout(skeletonTimer);
                        container.innerHTML = html.trim() || emptyProductsHtml();
                        container.classList.remove('product-filter-leaving');
                        container.classList.add('product-filter-entering');
                        if (container.dataset.productRestore === 'true') {
                            delete container.dataset.productRestore;
                            container.dataset.productRestoreCompleted = 'true';
                            [...container.children].forEach((card, index) => {
                                card.style.setProperty('--product-result-delay', `${Math.min(index * 55, 330)}ms`);
                            });
                            container.classList.add('product-filter-restoring');
                            setTimeout(() => container.classList.remove('product-filter-restoring'), 980);
                        }
                        window.refreshAksaDisplayCurrency?.(container);
                        window.initializeAksaPageEnhancements?.(container);
                        setTimeout(() => container.classList.remove('product-filter-entering'), 600);
                        refreshProductStocks();
                    })
                    .catch(error => {
                        if (error.name === 'AbortError' || requestSequence !== productRequestSequence) return;

                        clearTimeout(skeletonTimer);
                        delete container.dataset.productRestore;
                        container.classList.remove('product-filter-leaving');
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
                        clearTimeout(skeletonTimer);
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

                    const previousStatus = card.dataset.productStatus || '';
                    const previousStock = Number(card.dataset.productStock || 0);
                    const status = product.status === 'updating' ? 'updating' : 'ready';
                    const isUpdating = status === 'updating';
                    const hasReadyStock = !isUpdating && stock > 0;
                    const statusBadge = card.querySelector('[data-product-status-badge]');

                    card.dataset.productStatus = status;
                    card.dataset.productStock = String(stock);

                    if (statusBadge) {
                        statusBadge.textContent = product.status_label || (isUpdating ? 'Updating' : 'Ready');
                        statusBadge.classList.toggle('hidden', !isUpdating);
                        statusBadge.classList.toggle('product-status-badge-updating', isUpdating);
                    }

                    card.querySelector('[data-product-stock-icon-ready]')
                        ?.classList.toggle('hidden', !hasReadyStock);
                    card.querySelector('[data-product-stock-icon-unavailable]')
                        ?.classList.toggle('hidden', hasReadyStock);

                    const stockLabel = card.querySelector('[data-product-stock-label]');

                    if (stockLabel) {
                        stockLabel.textContent = isUpdating
                            ? 'Checkout paused · Discord alerts'
                            : (stock > 0 ? `${stock} available · Auto delivery` : 'Manual order via Discord');

                        if (previousStatus !== status || previousStock !== stock) {
                            stockLabel.classList.remove('product-stock-changed');
                            void stockLabel.offsetWidth;
                            stockLabel.classList.add('product-stock-changed');
                            setTimeout(() => stockLabel.classList.remove('product-stock-changed'), 680);
                        }
                    }
                });

                const totalAvailableStock = Number(snapshot.total_available_stock);

                if (totalStock && Number.isSafeInteger(totalAvailableStock) && totalAvailableStock >= 0) {
                    const previousTotal = Number(totalStock.textContent || 0);
                    totalStock.dataset.homeCountUp = String(totalAvailableStock);
                    totalStock.textContent = String(totalAvailableStock);

                    if (Number.isFinite(previousTotal) && previousTotal !== totalAvailableStock) {
                        totalStock.classList.remove('aksa-value-change');
                        void totalStock.offsetWidth;
                        totalStock.classList.add('aksa-value-change');
                        setTimeout(() => totalStock.classList.remove('aksa-value-change'), 480);
                    }
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

            if (restoredHomeView) {
                const needsFilteredRefresh = restoredSearch !== initialSearch
                    || currentCategory !== initialCategory;
                const restoredProducts = needsFilteredRefresh
                    ? fetchProducts(restoredSearch, currentCategory)
                    : Promise.resolve();

                restoredProducts.finally(() => {
                    if (!Number.isFinite(restoredScrollY)) return;

                    requestAnimationFrame(() => {
                        window.scrollTo({
                            top: Math.max(0, restoredScrollY),
                            behavior: 'auto',
                        });

                        requestAnimationFrame(() => {
                            pageContent?.dispatchEvent(new CustomEvent('aksa:history-scroll-restored'));
                        });
                    });
                });
            }

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

            window.addEventListener('aksa:before-page-swap', () => {
                persistHomeViewState();
                disposeHomePage();
            }, {
                once: true,
            });

            function emptyProductsHtml(message = 'No products match this filter yet.') {
                return `
                    <div class="empty-state col-span-full">
                        <span class="empty-state-icon">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="m21 8-9-5-9 5 9 5 9-5Z"></path>
                                <path d="M3 8v8l9 5 9-5V8"></path>
                                <path d="M12 13v8"></path>
                            </svg>
                        </span>
                        <span class="empty-state-title">No products found</span>
                        <p class="empty-state-copy">${escapeHtml(message)}</p>
                        <button type="button" class="order-action mt-4" data-clear-product-filters>
                            Clear Filters
                        </button>
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

        })();
    </script>
@endsection
