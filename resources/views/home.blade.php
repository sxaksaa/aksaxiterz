@extends('layouts.app')

@section('content')
    <section class="page-shell pt-6 md:pt-10 pb-5">
        <div class="max-w-3xl">
            <p class="text-sm font-semibold text-[#C084FC] mb-2">Gaming Tools Platform</p>

            <h1 class="text-3xl md:text-5xl font-bold tracking-normal mb-3">
                Unlock the tools you need to stay ahead and elevate your gameplay instantly.
            </h1>

            <p class="text-sm md:text-base text-gray-400 max-w-2xl">
                Secure checkout with instant delivery — no waiting, no hassle.
            </p>
            <p class="text-sm md:text-base text-gray-400 max-w-2xl">
                Seamlessly available across Android, iOS, PC, and laptop.
            </p>
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
