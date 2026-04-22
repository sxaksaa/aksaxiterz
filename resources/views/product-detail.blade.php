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
                @foreach ($product->features as $f)
                    <li>✔ {{ $f->name }}</li>
                @endforeach
            </ul>
        </div>

        <!-- PACKAGE -->
        <h2 class="mb-4 font-semibold">Pilih Paket</h2>

        <div class="flex gap-4 mb-10 flex-wrap">
            @foreach ($product->packages as $p)
                <div onclick="selectPackage(event, {{ $p->price }}, {{ $p->id }}, '{{ $p->name }}')"
                    class="package cursor-pointer p-4 bg-[#15151B] border border-[#27272A] rounded-xl w-40 transition">

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
                <span id="totalPrice" class="text-[#C084FC]">Rp 0</span>
            </div>

            @auth

                <!-- 🔥 MIDTRANS FIX (PAKAI FORM, BUKAN FETCH) -->
                <form method="POST" action="/process-order/{{ $product->id }}">
                    @csrf

                    <input type="hidden" name="package_id" id="selectedPackageIdMidtrans">

                    <button type="submit" id="payBtn" disabled
                        class="w-full bg-gray-600 text-gray-300 py-3 rounded-xl transition mb-4">
                        Pilih paket dulu
                    </button>
                </form>

                <!-- CRYPTO -->
                <div class="mb-3">
                    <select id="cryptoSelect" class="w-full bg-[#111115] border border-[#27272A] p-3 rounded-xl text-sm">
                        <option value="">Pilih Network USDT</option>
                        <option value="usdtbsc">USDT (BSC)</option>
                        <option value="usdterc20">USDT (Ethereum)</option>
                        <option value="usdttrc20">USDT (Tron)</option>
                        <option value="usdtmatic">USDT (Polygon)</option>
                        <option value="usdtton">USDT (TON)</option>
                    </select>
                </div>

                <!-- CRYPTO FORM -->
                <form method="POST" action="/pay-crypto/{{ $product->id }}">
                    @csrf

                    <input type="hidden" name="coin" id="selectedCoin">
                    <input type="hidden" name="package_id" id="selectedPackageIdCrypto">

                    <button type="submit" id="cryptoBtn" disabled
                        class="w-full bg-gray-600 text-gray-300 py-3 rounded-xl transition">
                        Pilih paket & network dulu
                    </button>
                </form>

            @else
                <a href="/auth/google" class="block text-center bg-[#9333EA] py-3 rounded-xl">
                    Login untuk membeli
                </a>
            @endauth

        </div>

    </div>

    <script>
        let selectedPackageId = null;

        window.onload = () => {
            document.getElementById('skeleton').style.display = 'none';
            document.getElementById('content').classList.remove('hidden');
        };

        function selectPackage(event, price, packageId, name) {

            selectedPackageId = packageId;

            document.getElementById('selectedPackage').innerText = name;
            document.getElementById('totalPrice').innerText = "Rp " + price.toLocaleString();

            // 🔥 kirim ke kedua form
            document.getElementById('selectedPackageIdMidtrans').value = packageId;
            document.getElementById('selectedPackageIdCrypto').value = packageId;

            document.querySelectorAll('.package').forEach(el => {
                el.classList.remove('border-[#9333EA]');
            });

            event.currentTarget.classList.add('border-[#9333EA]');

            // aktifkan midtrans
            const btn = document.getElementById('payBtn');
            btn.disabled = false;
            btn.innerText = "Checkout Midtrans";
            btn.classList.remove("bg-gray-600");
            btn.classList.add("bg-[#9333EA]", "cursor-pointer");

            checkCryptoReady();
        }

        document.getElementById('cryptoSelect').addEventListener('change', checkCryptoReady);

        function checkCryptoReady() {

            const coin = document.getElementById('cryptoSelect').value;
            const btn = document.getElementById('cryptoBtn');

            if (selectedPackageId && coin) {

                document.getElementById('selectedCoin').value = coin;

                btn.disabled = false;
                btn.innerText = "Bayar Crypto";

                btn.classList.remove("bg-gray-600");
                btn.classList.add("bg-green-500", "cursor-pointer");

            } else {

                btn.disabled = true;
                btn.innerText = "Pilih paket & network dulu";
            }
        }
    </script>
@endsection