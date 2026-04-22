@extends('layouts.app')

@section('content')

<!-- LOADING OVERLAY -->
<div id="pageLoader" class="fixed inset-0 bg-black/80 hidden items-center justify-center z-50">
    <div class="flex flex-col items-center gap-4">

        <div class="w-10 h-10 border-4 border-[#9333EA] border-t-transparent rounded-full animate-spin"></div>

        <span class="text-sm text-gray-300">Loading...</span>

    </div>
</div>


<!-- HEADER -->
<div class="w-full px-12 pt-10 pb-8 border-b border-[#27272A]">

    <!-- SEARCH -->
    <form method="GET" action="/" class="w-full mb-6">
        <input 
            type="text" 
            name="search"
            placeholder="Search products..."
            value="{{ request('search') }}"
            class="w-full px-6 py-5 rounded-xl 
            bg-[#15151B] border border-[#27272A] 
            text-white placeholder-gray-400

            focus:outline-none 
            focus:border-[#9333EA]
            focus:ring-2 focus:ring-[#9333EA]/40
            focus:scale-[1.01]

            transition duration-300"
        >
    </form>

    <!-- CATEGORY -->
    <div class="flex gap-3 flex-wrap">

        @php $active = request('category'); @endphp

        <a href="/" class="px-4 py-2 rounded-xl text-sm transition-all duration-300 ease-out
        {{ !$active ? 'bg-[#9333EA] text-white shadow-md shadow-[#9333EA]/40' : 'bg-[#15151B] hover:bg-[#9333EA]/20' }}">
            All
        </a>

        @foreach($categories as $category)
        <a href="/?category={{ $category->slug }}" 
        class="px-4 py-2 rounded-xl text-sm transition-all duration-300 ease-out
        {{ $active == $category->slug 
            ? 'bg-[#9333EA] text-white shadow-md shadow-[#9333EA]/40' 
            : 'bg-[#15151B] hover:bg-[#9333EA]/20' }}">
            {{ $category->name }}
        </a>
        @endforeach

    </div>

</div>


<!-- PRODUCTS -->
<div class="w-full px-12 pt-12 pb-20">

    <div class="max-w-7xl mx-auto">

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">

            @foreach($products as $product)
            <a href="/product/{{ $product->id }}"
            onclick="showLoader()"
            class="block fade-up bg-[#15151B] p-6 rounded-2xl 
            border border-[#27272A]

            hover:border-[#9333EA]/60
            hover:shadow-xl hover:shadow-[#9333EA]/25

            transform hover:-translate-y-2 hover:scale-[1.02]

            transition duration-300 ease-out">

                <h2 class="text-lg font-semibold mb-2">
                    {{ $product->name }}
                </h2>

                <p class="text-gray-400 text-sm mb-4">
                    {{ $product->description }}
                </p>

                <span class="text-[#C084FC] font-semibold">
                    Rp {{ number_format($product->price) }}
                </span>

            </a>
            @endforeach

        </div>

    </div>

</div>


<script>
function showLoader() {
    document.getElementById('pageLoader').classList.remove('hidden');
}
</script>

@endsection