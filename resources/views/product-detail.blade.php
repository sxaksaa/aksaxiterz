@extends('layouts.app')

@section('content')

<!-- SKELETON -->
<div id="skeleton" class="max-w-5xl mx-auto px-12 py-10 space-y-4 animate-pulse">
    <div class="h-6 bg-[#1f1f25] w-1/3 rounded"></div>
    <div class="h-4 bg-[#1f1f25] w-1/2 rounded"></div>
    <div class="h-32 bg-[#1f1f25] rounded-xl"></div>
</div>

<!-- CONTENT -->
<div id="content" class="hidden max-w-5xl mx-auto px-12 py-10 fade-up">

    <h1 class="text-2xl font-semibold mb-2">
        {{ $product->name }}
    </h1>

    <p class="text-gray-400 mb-6">
        {{ $product->description }}
    </p>

    <!-- FEATURES -->
    <div class="bg-[#15151B] border border-[#27272A] rounded-xl p-5 mb-10">
        <h3 class="mb-3 font-semibold">Features</h3>

        <ul class="space-y-1 text-sm text-gray-300">
            @foreach($product->features as $f)
                <li>✔ {{ $f->name }}</li>
            @endforeach
        </ul>
    </div>

    <!-- PACKAGE -->
    <h2 class="mb-4 font-semibold">Pilih Paket</h2>

    <div class="flex gap-4 mb-10 flex-wrap">

        @foreach($product->packages as $p)
        <div onclick="selectPackage(event, {{ $p->price }}, {{ $p->id }}, '{{ $p->name }}')" 
        class="package cursor-pointer p-4 bg-[#15151B] border border-[#27272A] rounded-xl w-40 transition duration-300">

            <p class="text-sm mb-1">{{ $p->name }}</p>
            <p class="text-[#C084FC] font-semibold">Rp {{ number_format($p->price) }}</p>

        </div>
        @endforeach

    </div>

    <!-- SUMMARY -->
    <div class="bg-[#15151B] border border-[#27272A] rounded-2xl p-6">

        <h3 class="mb-4 font-semibold">Ringkasan</h3>

        <div class="flex justify-between mb-2">
            <span>Paket</span>
            <span id="selectedPackage">Belum dipilih</span>
        </div>

        <div class="flex justify-between mb-4">
            <span>Total</span>
            <span id="totalPrice" class="text-[#C084FC] transition-all duration-300">Rp 0</span>
        </div>

        @auth
        <button id="payBtn" onclick="payNow()" disabled
        class="w-full bg-gray-600 text-gray-300 py-3 rounded-xl transition cursor-not-allowed">
            Pilih paket dulu
        </button>
        @else
        <a href="/auth/google" 
        class="block text-center bg-[#9333EA] py-3 rounded-xl">
            Login untuk membeli
        </a>
        @endauth

    </div>

</div>

<!-- MIDTRANS -->
<script src="https://app.sandbox.midtrans.com/snap/snap.js"
data-client-key="{{ config('midtrans.clientKey') }}"></script>

<script>
let selectedPackageId = null;
let isProcessing = false;

// skeleton → content
window.onload = () => {
    document.getElementById('skeleton').style.display = 'none';
    document.getElementById('content').classList.remove('hidden');
};

function selectPackage(event, price, packageId, name) {

    selectedPackageId = packageId;

    // animasi angka
    const total = document.getElementById('totalPrice');
    total.style.transform = "scale(1.1)";
    setTimeout(() => {
        total.innerText = "Rp " + price.toLocaleString();
        total.style.transform = "scale(1)";
    }, 100);

    document.getElementById('selectedPackage').innerText = name;

    document.querySelectorAll('.package').forEach(el => {
        el.classList.remove('border-[#9333EA]', 'shadow-lg', 'scale-105');
    });

    event.currentTarget.classList.add('border-[#9333EA]', 'shadow-lg', 'scale-105');

    // aktifkan tombol
    const btn = document.getElementById('payBtn');
    btn.disabled = false;
    btn.innerText = "Checkout";
    btn.classList.remove("bg-gray-600", "text-gray-300", "cursor-not-allowed");
    btn.classList.add("bg-[#9333EA]", "hover:bg-[#7E22CE]");
}

function payNow() {

    if (isProcessing) return;

    if (!selectedPackageId) return;

    isProcessing = true;

    const btn = document.getElementById('payBtn');
    btn.innerText = "Processing...";
    btn.classList.add("opacity-70");

    fetch('/process-order/{{ $product->id }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({
            package_id: selectedPackageId
        })
    })
    .then(res => res.json())
    .then(data => {

        window.snap.pay(data.snapToken, {
            onSuccess: function(){
                window.location.href = "/success";
            },
            onPending: function(){
                alert("Belum selesai 😅");
                isProcessing = false;
            },
            onError: function(){
                alert("Gagal 😢");
                isProcessing = false;
            },
            onClose: function(){
                isProcessing = false;
            }
        });

    });
}
</script>

@endsection