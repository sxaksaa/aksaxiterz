@extends('layouts.app')

@push('head')
    <script
        src="{{ config('midtrans.isProduction') ? 'https://app.midtrans.com/snap/snap.js' : 'https://app.sandbox.midtrans.com/snap/snap.js' }}"
        data-client-key="{{ config('midtrans.clientKey') }}">
    </script>
@endpush

@section('content')
    @php
        $stock = $product->available_license_stocks_count ?? 0;
    @endphp
    <div id="skeleton" class="max-w-5xl mx-auto px-4 sm:px-6 md:px-12 py-6 md:py-10 space-y-4 animate-pulse">
        <div class="h-6 bg-[#1f1f25] w-1/3 rounded"></div>
        <div class="h-4 bg-[#1f1f25] w-1/2 rounded"></div>
        <div class="h-32 bg-[#1f1f25] rounded-xl"></div>
    </div>

    <div id="content" class="hidden page-shell py-6 md:py-10 fade-up">

        <div class="grid gap-5 md:grid-cols-[1fr_320px] md:items-start mb-8">
            <div>
                <a href="/" class="text-sm text-[#C084FC] hover:text-white transition">Back to products</a>
                <h1 class="text-3xl md:text-5xl font-bold mt-3 mb-3">{{ $product->name }}</h1>
                <p class="text-gray-400 max-w-2xl">{{ $product->description }}</p>
            </div>

            <div class="panel-card p-4">
                <div class="text-xs uppercase text-gray-500 mb-2">Availability</div>
                <div class="flex items-end justify-between">
                    <div>
                        <div class="text-2xl font-bold {{ $stock > 0 ? 'text-[#C084FC]' : 'text-red-300' }}">
                            {{ $stock }}
                        </div>
                        <div class="text-sm text-gray-400">license ready</div>
                    </div>
                    <div class="text-xs text-gray-500">Auto delivery after paid</div>
                </div>
            </div>
        </div>

        <div class="panel-card p-5 mb-8">
            <h3 class="mb-3 font-semibold">Features</h3>
            <ul class="grid gap-2 text-sm text-gray-300 sm:grid-cols-2">
                @foreach ($product->features as $f)
                    <li class="rounded-lg bg-white/5 px-3 py-2">{{ $f->name }}</li>
                @endforeach
            </ul>
        </div>

        <h2 class="mb-4 font-semibold">Payment Method</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 md:gap-4 mb-8">

            <div onclick="selectPayment('midtrans')" id="btnMid"
                class="panel-card p-5 cursor-pointer payment-card flex flex-col gap-1">

                <div class="font-semibold">Transfer / E-Wallet</div>
                <span class="text-xs text-gray-400">QRIS All Payment (Bank, Dana, GoPay, etc)</span>

            </div>

            <div onclick="selectPayment('crypto')" id="btnCrypto"
                class="panel-card p-5 cursor-pointer payment-card flex flex-col gap-1">

                <div class="font-semibold">Crypto</div>
                <span class="text-xs text-gray-400">USDT Address</span>

            </div>

        </div>

        <!-- CRYPTO -->
        <div id="cryptoBox" class="hidden relative mb-6 fade-up z-10">

            <div onclick="toggleCryptoDropdown(event)" class="search-bar flex justify-between items-center cursor-pointer">

                <span id="selectedText">Select Network</span>
                <span id="arrow" class="transition-transform">v</span>
            </div>

            <div id="dropdownList" class="hidden w-full mt-2 panel-card overflow-hidden">

                <div onclick="selectNetwork('usdtbsc','BSC BNB Smart Chain (BEP20)', event)"
                    class="dropdown-item flex items-baseline gap-2" data-coin="usdtbsc" data-min="1">
                    <span class="font-bold text-white">BSC</span>
                    <span class="text-xs text-gray-500 ml-auto">Min $1</span>
                    <span class="font-normal text-gray-400 text-sm">BNB Smart Chain (BEP20)</span>
                </div>

                <div onclick="selectNetwork('usdttrc20','TRX Tron (TRC20)', event)"
                    class="dropdown-item flex items-baseline gap-2" data-coin="usdttrc20" data-min="10">
                    <span class="font-bold text-white">TRX</span>
                    <span class="text-xs text-gray-500 ml-auto">Min $10</span>
                    <span class="font-normal text-gray-400 text-sm">Tron (TRC20)</span>
                </div>

                <div onclick="selectNetwork('usdterc20','ETH Ethereum (ERC20)', event)"
                    class="dropdown-item flex items-baseline gap-2" data-coin="usdterc20" data-min="1">
                    <span class="font-bold text-white">ETH</span>
                    <span class="text-xs text-gray-500 ml-auto">Min $1</span>
                    <span class="font-normal text-gray-400 text-sm">Ethereum (ERC20)</span>
                </div>

                <div onclick="selectNetwork('usdtmatic','POL Polygon POS', event)"
                    class="dropdown-item flex items-baseline gap-2" data-coin="usdtmatic" data-min="1">
                    <span class="font-bold text-white">POL</span>
                    <span class="text-xs text-gray-500 ml-auto">Min $1</span>
                    <span class="font-normal text-gray-400 text-sm">Polygon POS</span>
                </div>

                <div onclick="selectNetwork('usdtton','TON The Open Network (TON)', event)"
                    class="dropdown-item flex items-baseline gap-2" data-coin="usdtton" data-min="1">
                    <span class="font-bold text-white">TON</span>
                    <span class="text-xs text-gray-500 ml-auto">Min $1</span>
                    <span class="font-normal text-gray-400 text-sm">The Open Network (TON)</span>
                </div>

            </div>

        </div>

        <h2 class="mb-4 font-semibold">Select Package</h2>

        <div class="grid grid-cols-1 gap-4 mb-10 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($product->packages as $p)
                @php
                    $packageStock = $p->available_license_stocks_count ?? 0;
                    $badge = null;
                    if (str_contains($p->name, 'Testing')) {
                        $badge = 'Test Flow';
                    } elseif (str_contains($p->name, '30')) {
                        $badge = 'Best Deal';
                    } elseif (str_contains($p->name, '90')) {
                        $badge = 'Premium';
                    }
                @endphp

                <div onclick="selectPackage(event, {{ (float) $p->price }}, {{ $p->id }}, {{ Illuminate\Support\Js::from($p->name) }}, {{ (float) $p->price_usdt }}, {{ $packageStock }})"
                    class="panel-card p-4 relative package transition {{ $packageStock > 0 ? 'cursor-pointer' : 'cursor-not-allowed opacity-60' }}">

                    @if ($badge)
                        <div class="badge">{{ $badge }}</div>
                    @endif

                    <p class="text-sm mb-1">
                        {{ str_replace(['1 Hari', '7 Hari', '30 Hari', 'Hari'], ['1 Day', '7 Days', '30 Days', 'Days'], $p->name) }}
                    </p>

                    <p class="text-[#C084FC] font-semibold price-text" data-idr="Rp {{ number_format($p->price) }}"
                        data-usd="${{ rtrim(rtrim($p->price_usdt, '0'), '.') }}">
                        Rp {{ number_format($p->price) }}
                    </p>

                    <p class="mt-3 text-xs {{ $packageStock > 0 ? 'text-gray-400' : 'text-red-300' }}">
                        {{ $packageStock > 0 ? $packageStock . ' left' : 'Out of stock' }}
                    </p>

                </div>
            @endforeach
        </div>

        <div id="summaryBox" class="hidden panel-card p-4 sm:p-6 fade-up transition-all duration-300">

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
            <form id="midForm" method="POST" action="/process-order/{{ $product->id }}" class="hidden">
                @csrf
                <input type="hidden" name="package_id" id="mid_package">
            </form>

            <!-- CRYPTO FORM -->
            <form id="cryptoForm" method="POST" action="/pay-crypto/{{ $product->id }}" class="hidden">
                @csrf
                <input type="hidden" name="package_id" id="crypto_package">
                <input type="hidden" name="coin" id="crypto_coin">
            </form>
        </div>
    </div>

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
                Payment Failed
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
        let selectedPackageStock = 0;
        let dropdownOpen = false;
        const hasStock = @json($stock > 0);
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

        window.addEventListener('load', () => {
            document.getElementById('skeleton').style.display = 'none';
            document.getElementById('content').classList.remove('hidden');
        });

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

            refreshNetworkAvailability();
            updateAllPrices();
            updatePrice();
            showSummary();
        }

        /* =========================
           PACKAGE
        ========================= */
        function selectPackage(e, price, id, name, usd, stock) {
            if (stock <= 0) {
                alert('This package is out of stock');
                return;
            }

            selectedPackageId = id;
            selectedPrice = price;
            selectedUsd = usd;
            selectedPackageStock = stock;

            document.querySelectorAll('.package')
                .forEach(el => el.classList.remove('active'));

            e.currentTarget.classList.add('active');

            document.getElementById('selectedPackage')
                .innerText = formatPackageName(name);
            refreshNetworkAvailability();
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

        function networkMinimum(coin) {
            return coin === 'usdttrc20' ? 10 : 1;
        }

        function isNetworkDisabled(coin) {
            return selectedUsd > 0 && selectedUsd < networkMinimum(coin);
        }

        function refreshNetworkAvailability() {
            document.querySelectorAll('.dropdown-item').forEach(item => {
                const coin = item.dataset.coin;
                const disabled = isNetworkDisabled(coin);

                item.classList.toggle('disabled', disabled);
                item.setAttribute('aria-disabled', disabled ? 'true' : 'false');
            });

            if (selectedCoin && isNetworkDisabled(selectedCoin)) {
                selectedCoin = null;
                document.getElementById('selectedText').innerText = 'Select Network';
            }
        }

        function formatPackageName(name) {
            return name
                .replace('1 Hari', '1 Day')
                .replace('7 Hari', '7 Days')
                .replace('30 Hari', '30 Days')
                .replace('Hari', 'Days');
        }

        /* =========================
           DROPDOWN
        ========================= */
        function toggleCryptoDropdown(e) {

            e.stopPropagation();

            const box = document.getElementById('dropdownList');
            const arrow = document.getElementById('arrow');

            dropdownOpen = !dropdownOpen;

            box.classList.toggle('hidden');
            arrow.style.transform = dropdownOpen ? 'rotate(180deg)' : 'rotate(0deg)';
        }

        function selectNetwork(value, text, e) {
            if (e?.currentTarget?.classList.contains('disabled')) {
                return;
            }

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

        async function fetchPaymentJson(form) {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: new FormData(form),
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                const error = new Error(data.message || 'Payment failed');
                error.redirectUrl = data.redirect_url;
                throw error;
            }

            return data;
        }

        function prepareCryptoTab() {
            const tab = window.open('about:blank', '_blank');

            if (!tab) {
                return null;
            }

            try {
                tab.opener = null;
                tab.document.title = 'Opening payment...';
                tab.document.body.innerHTML =
                    '<p style="font-family: system-ui, sans-serif; padding: 24px;">Opening payment...</p>';
            } catch (error) {
                // Some browsers restrict access quickly after opening a tab.
            }

            return tab;
        }

        function openCryptoInvoice(tab, paymentUrl) {
            if (!paymentUrl) {
                throw new Error('Crypto invoice URL is not available');
            }

            if (tab && !tab.closed) {
                tab.location.replace(paymentUrl);
                return true;
            }

            const newTab = window.open(paymentUrl, '_blank');

            if (newTab) {
                try {
                    newTab.opener = null;
                } catch (error) {}

                return true;
            }

            return false;
        }

        function closeCryptoTab(tab) {
            if (tab && !tab.closed) {
                tab.close();
            }
        }

        function openMidtransPayment(token) {
            if (!window.snap) {
                window.location.href = `/midtrans-pay?token=${encodeURIComponent(token)}`;
                return;
            }

            window.snap.pay(token, {
                onSuccess: function() {
                    window.location.href = '/licenses';
                },
                onPending: function() {
                    window.location.href = '/orders';
                },
                onError: function() {
                    window.location.href = '/orders';
                },
                onClose: function() {
                    window.location.href = '/orders';
                },
            });
        }

        function resetPayButton() {
            const btn = document.getElementById('payMainBtn');
            if (!btn || !hasStock) return;

            btn.disabled = false;
            btn.innerText = 'Pay Now';
            btn.classList.remove('opacity-60', 'bg-gray-500', 'cursor-not-allowed', 'pointer-events-none');
        }

        /* =========================
           PAY BUTTON
        ========================= */
        document.getElementById('payMainBtn').onclick = async function() {

            if (this.disabled) return;

            if (!selectedPackageId) {
                alert('Select package first');
                return;
            }

            if (selectedPackageStock <= 0) {
                alert('This package is out of stock');
                return;
            }

            if (!selectedPayment) {
                alert('Select payment method');
                return;
            }

            if (selectedPayment === 'crypto' && !selectedCoin) {
                alert('Select network first');
                return;
            }

            this.innerText = "Processing...";
            this.classList.add('opacity-60')
            this.classList.add('bg-gray-500', 'cursor-not-allowed', 'pointer-events-none')
            this.disabled = true;

            const productId = {{ $product->id }};

            if (selectedPayment === 'midtrans') {

                document.getElementById('mid_package').value = selectedPackageId;

                const form = document.getElementById('midForm');
                sessionStorage.setItem('last_product', window.location.href);

                try {
                    const data = await fetchPaymentJson(form);
                    openMidtransPayment(data.snap_token);
                } catch (error) {
                    if (error.redirectUrl) {
                        window.location.href = error.redirectUrl;
                        return;
                    }

                    alert(error.message || 'Payment failed');
                    resetPayButton();
                }
            }

            if (selectedPayment === 'crypto') {

                let min = networkMinimum(selectedCoin);

                if (selectedUsd < min) {
                    alert(`Minimum payment for ${selectedCoin} is $${min}`);

                    this.disabled = false
                    this.innerText = "Pay Now"
                    this.classList.remove('opacity-60', 'bg-gray-500', 'cursor-not-allowed', 'pointer-events-none')

                    return;
                }

                const invoiceTab = prepareCryptoTab();

                document.getElementById('crypto_package').value = selectedPackageId;
                document.getElementById('crypto_coin').value = selectedCoin;

                const form = document.getElementById('cryptoForm');

                try {
                    const data = await fetchPaymentJson(form);

                    if (openCryptoInvoice(invoiceTab, data.payment_url)) {
                        window.location.href = '/orders';
                    } else {
                        window.location.href = data.payment_url;
                    }
                } catch (error) {
                    closeCryptoTab(invoiceTab);

                    if (error.redirectUrl) {
                        window.location.href = error.redirectUrl;
                        return;
                    }

                    alert(error.message || 'Payment failed');
                    resetPayButton();
                }
            }
        };

        window.addEventListener('pageshow', function() {
            const btn = document.getElementById('payMainBtn')
            if (btn) {
                if (!hasStock) {
                    btn.disabled = true
                    btn.innerText = "Out of Stock"
                    return
                }

                btn.disabled = false
                btn.innerText = "Pay Now"
                btn.classList.remove('opacity-60', 'bg-gray-500', 'cursor-not-allowed', 'pointer-events-none')
            }
        });
    </script>
@endsection
