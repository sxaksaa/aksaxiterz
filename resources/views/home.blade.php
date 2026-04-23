@extends('layouts.app')

@section('content')

<!-- LOADING -->
<div id="pageLoader" class="fixed inset-0 bg-black/80 hidden items-center justify-center z-50">
    <div class="flex flex-col items-center gap-4">
        <div class="w-10 h-10 border-4 border-[#9333EA] border-t-transparent rounded-full animate-spin"></div>
        <span class="text-sm text-gray-300">Loading...</span>
    </div>
</div>

<!-- HEADER CLEAN -->
<div class="px-12 pt-10 pb-6">
    <h1 class="text-2xl font-semibold">
        Marketplace
    </h1>
</div>

<!-- SEARCH (ROUNDED PREMIUM) -->
<div class="px-12 pb-6">
    <form method="GET" action="/" class="relative">
        <input type="text" name="search"
            placeholder="Search tools, products..."
            value="{{ request('search') }}"
            class="search-bar">

        <div class="absolute left-5 top-1/2 -translate-y-1/2 text-gray-400 text-sm">
            🔍
        </div>
    </form>
</div>

<!-- CATEGORY -->
<div class="px-12 pb-10 flex gap-3 flex-wrap">

    @php $active = request('category'); @endphp

    <a href="/" class="category-chip {{ !$active ? 'active' : '' }}">
        All
    </a>

    @foreach ($categories as $category)
        <a href="/?category={{ $category->slug }}"
            class="category-chip {{ $active == $category->slug ? 'active' : '' }}">
            {{ $category->name }}
        </a>
    @endforeach

</div>

<!-- PRODUCTS -->
<div class="w-full px-12 pb-20">
    <div class="max-w-6xl mx-auto">

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">

            @foreach ($products as $product)

                @php
                    $minPackage = $product->packages->sortBy('price')->first();
                    $badge = null;

                    if ($minPackage && $minPackage->price <= 50000) $badge = '🔥 Hot';
                    if ($minPackage && $minPackage->price >= 100000) $badge = '💎 Premium';
                @endphp

                <a href="/product/{{ $product->id }}" onclick="showLoader()"
                    class="product-card fade-up">

                    @if($badge)
                        <div class="badge">{{ $badge }}</div>
                    @endif

                    <h2 class="text-lg font-semibold mb-2">
                        {{ $product->name }}
                    </h2>

                    <p class="text-gray-400 text-sm mb-4 line-clamp-2">
                        {{ $product->description }}
                    </p>

                    <span class="price">
                        @if ($minPackage)
                            Rp {{ number_format($minPackage->price) }}
                            <span class="mx-1">/</span>
                            ${{ rtrim(rtrim($minPackage->price_usdt, '0'), '.') }}
                        @else
                            -
                        @endif
                    </span>

                </a>

            @endforeach

        </div>

    </div>
</div>

<style>

/* SEARCH BAR (PILL + ANIMASI) */
.search-bar{
    width:100%;
    padding:14px 20px 14px 45px;
    border-radius:999px;
    background:#15151B;
    border:1px solid #27272A;
    color:white;
    transition:.3s;
}
.search-bar:focus{
    border-color:#9333EA;
    outline:none;
    box-shadow:0 0 15px rgba(147,51,234,0.4);
}
.search-bar:hover{
    transform:translateY(-2px);
    border-color:#9333EA;
}

/* CATEGORY */
.category-chip{
    padding:8px 14px;
    border-radius:999px;
    font-size:13px;
    background:#15151B;
    border:1px solid #27272A;
    transition:.25s;
}
.category-chip:hover{
    transform:translateY(-2px);
    border-color:#9333EA;
    box-shadow:0 5px 15px rgba(147,51,234,0.2);
}
.category-chip.active{
    background:#9333EA;
    color:white;
    box-shadow:0 0 10px rgba(147,51,234,0.5);
}

/* CARD */
.product-card{
    position:relative;
    display:block;
    background:#15151B;
    padding:20px;
    border-radius:16px;
    border:1px solid #27272A;
    transition:.3s;
}
.product-card:hover{
    transform:translateY(-6px) scale(1.02);
    border-color:#9333EA;
    box-shadow:0 10px 30px rgba(147,51,234,0.3);
}

/* PRICE */
.price{
    color:#C084FC;
    font-weight:600;
    font-size:14px;
}

/* BADGE */
.badge{
    position:absolute;
    top:10px;
    right:10px;
    font-size:11px;
    background:#9333EA;
    padding:4px 8px;
    border-radius:8px;
}

/* FADE */
.fade-up{
    animation:fadeUp .6s ease forwards;
}
@keyframes fadeUp{
    from{
        opacity:0;
        transform:translateY(20px);
    }
    to{
        opacity:1;
        transform:translateY(0);
    }
}

</style>

<script>
function showLoader() {
    document.getElementById('pageLoader').classList.remove('hidden');
}
</script>

@endsection