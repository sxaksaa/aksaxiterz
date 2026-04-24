@extends('layouts.app')

@section('content')
    @php
        $stock = \App\Models\LicenseStock::where('product_id', $product->id)->where('is_sold', false)->count();
    @endphp
    <div id="skeleton" class="max-w-5xl mx-auto px-4 sm:px-6 md:px-12 py-6 md:py-10 space-y-4 animate-pulse">
        <div class="h-6 bg-[#1f1f25] w-1/3 rounded"></div>
        <div class="h-4 bg-[#1f1f25] w-1/2 rounded"></div>
        <div class="h-32 bg-[#1f1f25] rounded-xl"></div>
    </div>

    <div id="content" class="hidden max-w-5xl mx-auto px-4 sm:px-6 md:px-12 py-6 md:py-10 fade-up">

        <h1 class="text-2xl font-semibold mb-2">{{ $product->name }}</h1>
        <p class="text-gray-400 mb-6">{{ $product->description }}</p>

        <!-- FEATURES -->
        <div class="card-glow p-5 mb-10">
            <h3 class="mb-3 font-semibold">Features</h3>
            <ul class="space-y-1 text-sm text-gray-300">
                @foreach ($product->features as $f)
                    <li>✔ {{ $f->name }}</li>
                @endforeach
            </ul>
        </div>

        <!-- PAYMENT -->
        <h2 class="mb-4 font-semibold">Payment Method</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 md:gap-4 mb-8">

            <div onclick="selectPayment('midtrans')" id="btnMid"
                class="card-glow p-5 cursor-pointer payment-card flex flex-col items-center justify-center gap-1">

                <div class="text-lg">💳</div>
                <div>Transfer / E-Wallet</div>
                <span class="text-xs text-gray-400">QRIS All Payment (Bank, Dana, GoPay, etc)</span>

            </div>

            <div onclick="selectPayment('crypto')" id="btnCrypto"
                class="card-glow p-5 cursor-pointer payment-card flex flex-col items-center justify-center gap-1">

                <div class="text-lg">🪙</div>
                <div>Crypto</div>
                <span class="text-xs text-gray-400">USDT Adress</span>

            </div>

        </div>

        <!-- CRYPTO -->
        <div id="cryptoBox" class="hidden relative mb-6 fade-up">

            <div onclick="toggleDropdown(event)" class="search-bar flex justify-between items-center cursor-pointer">

                <span id="selectedText">Select Network</span>
                <span id="arrow" class="transition-transform">⌄</span>
            </div>

            <div id="dropdownList" class="hidden absolute w-full mt-2 card-glow overflow-hidden z-50">

                <div onclick="selectNetwork('usdtbsc','BSC BNB Smart Chain (BEP20)')"
                    class="dropdown-item flex items-baseline gap-2">
                    <span class="font-bold text-white">BSC</span>
                    <span class="font-normal text-gray-400 text-sm">BNB Smart Chain (BEP20)</span>
                    <span class="text-xs italic text-yellow-500 ml-auto">⭐ Recommended</span>
                </div>

                <div onclick="selectNetwork('usdttrc20','TRX Tron (TRC20)')"
                    class="dropdown-item flex items-baseline gap-2">
                    <span class="font-bold text-white">TRX</span>
                    <span class="font-normal text-gray-400 text-sm">Tron (TRC20)</span>
                </div>

                <div onclick="selectNetwork('usdterc20','ETH Ethereum (ERC20)')"
                    class="dropdown-item flex items-baseline gap-2">
                    <span class="font-bold text-white">ETH</span>
                    <span class="font-normal text-gray-400 text-sm">Ethereum (ERC20)</span>
                </div>

                <div onclick="selectNetwork('usdtmatic','POL Polygon POS')" class="dropdown-item flex items-baseline gap-2">
                    <span class="font-bold text-white">POL</span>
                    <span class="font-normal text-gray-400 text-sm">Polygon POS</span>
                </div>

                <div onclick="selectNetwork('usdtton','TON The Open Network (TON)')"
                    class="dropdown-item flex items-baseline gap-2">
                    <span class="font-bold text-white">TON</span>
                    <span class="font-normal text-gray-400 text-sm">The Open Network (TON)</span>
                </div>

            </div>

        </div>

        <!-- PACKAGE -->
        <h2 class="mb-4 font-semibold">Select Package</h2>

        <div class="flex gap-4 mb-10 flex-wrap">
            @foreach ($product->packages as $p)
                @php
                    $badge = null;
                    if (str_contains($p->name, '30')) {
                        $badge = '⭐ Best Deal';
                    }
                    if (str_contains($p->name, '90')) {
                        $badge = '💎 Premium';
                    }
                @endphp

                <div onclick="selectPackage(event, {{ $p->price }}, {{ $p->id }}, '{{ $p->name }}', {{ $p->price_usdt }})"
                    class="card-glow p-4 w-full sm:w-44 relative cursor-pointer package transition">

                    @if ($badge)
                        <div class="badge">{{ $badge }}</div>
                    @endif

                    <p class="text-sm mb-1">
                        {{ str_replace('Hari', 'Days', $p->name) }}
                    </p>

                    <p class="text-[#C084FC] font-semibold price-text" data-idr="Rp {{ number_format($p->price) }}"
                        data-usd="${{ rtrim(rtrim($p->price_usdt, '0'), '.') }}">
                        Rp {{ number_format($p->price) }}
                    </p>

                </div>
            @endforeach
        </div>

        <!-- SUMMARY -->
        <div id="summaryBox" class="hidden card-glow p-4 sm:p-6 fade-up transition-all duration-300">

            <h3 class="mb-4 font-semibold">Order Summary</h3>

            <div class="flex justify-between mb-2">
                <span>Package</span>
                <span id="selectedPackage">-</span>
            </div>

            <div class="flex justify-between mb-2">
                <span>Total</span>
                <span id="totalPrice" class="text-[#C084FC]">-</span>
            </div>

            <button id="payMainBtn"
                class="btn-main mt-4 w-full 
    {{ $stock <= 0 ? 'bg-gray-600 cursor-not-allowed opacity-60' : '' }}"
                {{ $stock <= 0 ? 'disabled' : '' }}>

                {{ $stock <= 0 ? 'Out of Stock' : 'Pay Now' }}

            </button>
            <!-- MIDTRANS FORM -->
            <form id="midForm" method="POST" class="hidden">
                @csrf
                <input type="hidden" name="package_id" id="mid_package">
            </form>

            <!-- CRYPTO FORM -->
            <form id="cryptoForm" method="POST" class="hidden">
                @csrf
                <input type="hidden" name="package_id" id="crypto_package">
                <input type="hidden" name="coin" id="crypto_coin">
            </form>
        </div>
    </div> {{-- content --}}

    @if ($errors->has('payment'))
        <div id="toastError"
            class="
    fixed bottom-6 right-6 z-50
    bg-red-500/10 border border-red-500/30
    text-red-300 text-sm
    px-5 py-3 rounded-xl
    shadow-xl backdrop-blur-md

    opacity-0 translate-y-6
    transition-all duration-300
">

            <div class="font-semibold text-red-400 mb-1">
                Payment Failed 😢
            </div>

            <div>
                {{ $errors->first('payment') }}
            </div>

        </div>

        <script>
            const toast = document.getElementById('toastError');

            setTimeout(() => {
                toast.classList.remove('opacity-0', 'translate-y-6');
            }, 100);

            setTimeout(() => {
                toast.classList.add('opacity-0', 'translate-y-6');
            }, 4000);
        </script>
    @endif

    <script>
        let selectedPackageId = null;
        let selectedPayment = null;
        let selectedCoin = null;
        let selectedPrice = 0;
        let selectedUsd = 0;
        let dropdownOpen = false;

        window.onload = () => {
            document.getElementById('skeleton').style.display = 'none';
            document.getElementById('content').classList.remove('hidden');
        };

        /* =========================
           PAYMENT
        ========================= */
        function selectPayment(type) {

            selectedPayment = type;

            document.querySelectorAll('.payment-card').forEach(el => {
                el.classList.remove('active');
            });

            const target = type === 'crypto' ? 'btnCrypto' : 'btnMid';
            const el = document.getElementById(target);

            el.classList.add('active');

            el.style.transform = 'scale(1.05)';
            setTimeout(() => el.style.transform = '', 150);

            document.getElementById('cryptoBox')
                .classList.toggle('hidden', type !== 'crypto');

            updateAllPrices();
            updatePrice();
            showSummary();
        }

        /* =========================
           PACKAGE
        ========================= */
        function selectPackage(e, price, id, name, usd) {

            selectedPackageId = id;
            selectedPrice = price;
            selectedUsd = usd;

            document.querySelectorAll('.package')
                .forEach(el => el.classList.remove('active'));

            e.currentTarget.classList.add('active');

            document.getElementById('selectedPackage')
                .innerText = name.replace('Hari', 'Days');
            updatePrice();
            showSummary();
        }

        /* =========================
           PRICE SWITCH
        ========================= */
        function updateAllPrices() {
            document.querySelectorAll('.price-text').forEach(el => {
                el.innerText = selectedPayment === 'crypto' ?
                    el.dataset.usd :
                    el.dataset.idr;
            });
        }

        function updatePrice() {
            let text = '-';

            if (selectedPayment === 'midtrans')
                text = "Rp " + selectedPrice.toLocaleString();

            if (selectedPayment === 'crypto')
                text = "$" + selectedUsd;

            document.getElementById('totalPrice').innerText = text;
        }

        /* =========================
           DROPDOWN
        ========================= */
        function toggleDropdown(e) {

            e.stopPropagation();

            const box = document.getElementById('dropdownList');
            const arrow = document.getElementById('arrow');

            dropdownOpen = !dropdownOpen;

            box.classList.toggle('hidden');
            arrow.style.transform = dropdownOpen ? 'rotate(180deg)' : 'rotate(0deg)';
        }

        function selectNetwork(value, text) {

            const el = event.target;

            selectedCoin = value;

            document.getElementById('selectedText').innerText = text;

            dropdownOpen = false;

            document.getElementById('dropdownList').classList.add('hidden');
            document.getElementById('arrow').style.transform = 'rotate(0deg)';
        }

        window.addEventListener('click', function(e) {
            if (!e.target.closest('#cryptoBox')) {
                document.getElementById('dropdownList').classList.add('hidden');
                dropdownOpen = false;
                document.getElementById('arrow').style.transform = 'rotate(0deg)';
            }
        });



        /* =========================
           SUMMARY
        ========================= */
        function showSummary() {
            if (selectedPackageId && selectedPayment) {
                document.getElementById('summaryBox').classList.remove('hidden');
            }
        }

        /* =========================
           PAY BUTTON
        ========================= */
        document.getElementById('payMainBtn').onclick = function() {

            if (!selectedPackageId) {
                alert('Select package first');
                return;
            }

            if (!selectedPayment) {
                alert('Select payment method');
                return;
            }

            this.innerText = "Processing...";
            this.disabled = true;


            const productId = {{ $product->id }};

            if (selectedPayment === 'midtrans') {

                document.getElementById('mid_package').value = selectedPackageId;

                const form = document.getElementById('midForm');
                form.action = `/process-order/${productId}`;
                form.submit();
            }

            if (selectedPayment === 'crypto') {

                if (!selectedCoin) {
                    alert('Select network');
                    return;
                }



                document.getElementById('crypto_package').value = selectedPackageId;
                document.getElementById('crypto_coin').value = selectedCoin;

                const form = document.getElementById('cryptoForm');
                form.action = `/pay-crypto/${productId}`;
                form.submit();
            }
        };
    </script>
@endsection
