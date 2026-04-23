@foreach ($products as $product)

@php
    $minPackage = $product->packages->sortBy('price')->first();
    $badge = null;

    if ($minPackage && $minPackage->price <= 50000) $badge = '🔥 Hot';
    if ($minPackage && $minPackage->price >= 100000) $badge = '💎 Premium';
@endphp

<a href="/product/{{ $product->id }}"
   onclick="showLoader()"
   class="card-glow fade-up p-5 block relative">

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
            /
            ${{ rtrim(rtrim($minPackage->price_usdt, '0'), '.') }}
        @else
            -
        @endif
    </span>

</a>

@endforeach