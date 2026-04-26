@extends('layouts.app')

@section('content')
    <div id="pageLoader" class="fixed inset-0 bg-black/80 hidden items-center justify-center z-50">
        <div class="flex flex-col items-center gap-4">
            <div class="w-10 h-10 border-4 border-[#9333EA] border-t-transparent rounded-full animate-spin"></div>
            <span class="text-sm text-gray-300">Loading...</span>
        </div>
    </div>

    <section class="page-shell pt-6 md:pt-10 pb-5">
        <div class="grid gap-5 md:grid-cols-[1fr_360px] md:items-end">
            <div>
                <p class="text-sm font-semibold text-[#C084FC] mb-2">Digital license store</p>
                <h1 class="text-3xl md:text-5xl font-bold tracking-normal mb-3">Pilih produk, bayar, license langsung masuk.</h1>
                <p class="text-sm md:text-base text-gray-400 max-w-2xl">
                    Flow dibuat untuk pembayaran otomatis via Midtrans atau crypto, dengan stok license yang dikunci saat callback berhasil.
                </p>
            </div>

            <div class="panel-card p-4">
                <div class="text-xs uppercase text-gray-500 mb-1">Live flow</div>
                <div class="grid grid-cols-3 gap-2 text-center text-xs">
                    <div class="rounded-lg bg-white/5 p-3">
                        <div class="font-semibold text-white">Order</div>
                        <div class="text-gray-500 mt-1">10 min</div>
                    </div>
                    <div class="rounded-lg bg-white/5 p-3">
                        <div class="font-semibold text-white">Pay</div>
                        <div class="text-gray-500 mt-1">Auto</div>
                    </div>
                    <div class="rounded-lg bg-white/5 p-3">
                        <div class="font-semibold text-white">License</div>
                        <div class="text-gray-500 mt-1">Instant</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="page-shell pb-4 md:pb-6">
        <div class="max-w-2xl">
            <input type="text" id="searchInput" placeholder="Search tools, products..."
                class="search-bar w-full text-sm md:text-base" value="{{ request('search') }}">
        </div>
    </div>

    <div class="page-shell pb-6 md:pb-10 flex gap-2 md:gap-3 flex-wrap">

        @php $active = request('category'); @endphp

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

    <div class="page-shell pb-16 md:pb-20">
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

                document.querySelectorAll('.category-chip')
                    .forEach(e => e.classList.remove('active'));

                el.classList.add('active');

                fetchProducts(searchInput.value, cat);
            }

            function fetchProducts(search, category) {

                container.style.opacity = 0;

                const params = new URLSearchParams({
                    search,
                    category,
                });

                fetch(`/api/products?${params.toString()}`)
                    .then(res => res.text())
                    .then(html => {

                        setTimeout(() => {
                            container.innerHTML = html;
                            container.style.opacity = 1;
                        }, 150);

                    });
            }

        });

        /* LOADER */
        function showLoader() {
            document.getElementById('pageLoader').classList.remove('hidden');
        }
    </script>
@endsection
