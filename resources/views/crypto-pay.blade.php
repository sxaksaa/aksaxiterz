@extends('layouts.app')

@section('content')

<div class="max-w-xl mx-auto py-20 text-center fade-up">

    <h1 class="text-2xl font-semibold mb-6 text-green-400">
        Payment Crypto 💸
    </h1>

    <p class="text-gray-400 mb-6">
        Kirim sesuai jumlah di bawah ini
    </p>

    <div class="bg-[#15151B] border border-[#27272A] rounded-xl p-6 mb-6">

        <!-- AMOUNT -->
        <p class="text-sm text-gray-400 mb-2">Amount</p>
        <div class="text-lg font-mono text-[#C084FC] mb-4">
            {{ $data['pay_amount'] ?? $data['price_amount'] ?? '0' }} 
            {{ strtoupper($data['pay_currency'] ?? 'CRYPTO') }}
        </div>

        <!-- ADDRESS -->
        <p class="text-sm text-gray-400 mb-2">Address</p>
        <div id="address" class="text-sm break-all mb-4 text-gray-300">
            {{ $data['pay_address'] ?? 'Address tidak tersedia' }}
        </div>

        <button onclick="copyAddress()" 
        class="bg-[#9333EA] px-4 py-2 rounded-lg hover:bg-[#7E22CE] transition">
            Copy Address
        </button>

    </div>

    <!-- INFO -->
    <p class="text-xs text-gray-500">
        ⚠️ Pastikan kirim sesuai network & jumlah yang tepat  
        <br>
        Tunggu beberapa menit setelah pembayaran
    </p>

</div>

<script>
function copyAddress() {
    const text = document.getElementById('address').innerText;
    navigator.clipboard.writeText(text);
    alert("Copied!");
}
</script>

@endsection