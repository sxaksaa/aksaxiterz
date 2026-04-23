@extends('layouts.app')

@section('content')

<!-- LOADING -->

<div id="pageLoader" class="fixed inset-0 bg-black/80 hidden items-center justify-center z-50">
    <div class="flex flex-col items-center gap-4">
        <div class="w-10 h-10 border-4 border-[#9333EA] border-t-transparent rounded-full animate-spin"></div>
        <span class="text-sm text-gray-300">Loading...</span>
    </div>
</div>

<!-- SEARCH -->

<div class="px-4 md:px-12 pt-10 pb-6">
    <div class="max-w-xl mx-auto">
        <input type="text"
            id="searchInput"
            placeholder="Search tools, products..."
            class="search-bar w-full"
            value="{{ request('search') }}">
    </div>
</div>

<!-- CATEGORY -->

<div class="px-4 md:px-12 pb-10 flex gap-3 flex-wrap justify-center">


@php $active = request('category'); @endphp

<a href="#"
    onclick="filterCategory('', this)"
    class="category-chip {{ !$active ? 'active' : '' }}">
    All
</a>

@foreach ($categories as $category)
    <a href="#"
        onclick="filterCategory('{{ $category->slug }}', this)"
        class="category-chip {{ $active == $category->slug ? 'active' : '' }}">
        {{ $category->name }}
    </a>
@endforeach


</div>

<!-- PRODUCTS -->

<div class="w-full px-4 md:px-12 pb-20">
    <div class="max-w-6xl mx-auto">


    <div id="productContainer"
        class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 transition-opacity duration-200">

        @include('partials.product-card', ['products' => $products])

    </div>

</div>


</div>

<script>
document.addEventListener('DOMContentLoaded', () => {

    let timeout;
    let currentCategory = '';

    const searchInput = document.getElementById('searchInput');
    const container = document.getElementById('productContainer');

    if (!searchInput || !container) return;

    searchInput.addEventListener('input', function () {

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

        fetch(`/api/products?search=${search}&category=${category}`)
            .then(res => res.text())
            .then(html => {

                setTimeout(() => {
                    container.innerHTML = html;
                    container.style.opacity = 1;
                }, 150);

            });
    }

});

/* LOADER (optional dipakai di card klik) */
function showLoader() {
    document.getElementById('pageLoader').classList.remove('hidden');
}
</script>

@endsection
