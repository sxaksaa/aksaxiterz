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

    <div id="content" class="page-shell py-6 md:py-10">

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

                {{ $stock <= 0 ? 'Out of Stock' : (auth()->check() ? 'Pay Now' : 'Login to Pay') }}

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

    @php $paymentError = $errors->first('payment'); @endphp
    <script>
        let selectedPackageId = null;
        let selectedPayment = null;
        let selectedCoin = null;
        let selectedPrice = 0;
        let selectedUsd = 0;
        let selectedPackageStock = 0;
        let dropdownOpen = false;
        const hasStock = @json($stock > 0);
        const isAuthenticated = @json(auth()->check());
        const loginUrl = `/auth/google?redirect=${encodeURIComponent(window.location.href)}`;
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

        @if ($paymentError)
            document.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => showToast('Payment failed', @json($paymentError), null, 'error'), 100);
            });
        @endif

        function showToast(title, message, redirectAfter = null, variant = 'info') {
            window.showAppToast?.(title, message, {
                redirectAfter,
                variant,
            });
        }

        function requireLogin() {
            showToast('Login required', 'Please login with Google before checkout.', loginUrl, 'warning');
        }

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

            showToast(
                'Payment selected',
                type === 'crypto' ? 'Crypto payment is active. Choose a network next.' :
                'Transfer and e-wallet payment is active.',
                null,
                'success'
            );
        }

        /* =========================
           PACKAGE
        ========================= */
        function selectPackage(e, price, id, name, usd, stock) {
            if (stock <= 0) {
                showToast('Out of stock', 'This package is out of stock.', null, 'warning');
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

            const priceText = selectedPayment === 'crypto' ?
                `$${Number(usd).toLocaleString()}` :
                `Rp ${Number(price).toLocaleString()}`;

            showToast(
                'Package selected',
                `${formatPackageName(name)} - ${priceText} (${stock} left).`,
                null,
                'success'
            );
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
                const min = networkMinimum(selectedCoin);
                selectedCoin = null;
                document.getElementById('selectedText').innerText = 'Select Network';
                showToast('Network reset', `Selected package is below the $${min} minimum.`, null, 'warning');
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
                const min = networkMinimum(value);
                showToast('Network unavailable', `This network needs at least $${min}.`, null, 'warning');
                return;
            }

            selectedCoin = value;

            document.getElementById('selectedText').innerText = text;

            dropdownOpen = false;

            document.getElementById('dropdownList').classList.add('hidden');
            document.getElementById('arrow').style.transform = 'rotate(0deg)';

            showToast('Network selected', text, null, 'success');
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
                const message = response.status === 401 ?
                    'Please login with Google before checkout.' :
                    (data.message || 'Payment failed');
                const error = new Error(message);
                error.status = response.status;
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

        async function syncMidtransOrder(orderId) {
            if (!orderId) return null;

            const response = await fetch(`/sync-midtrans-order/${encodeURIComponent(orderId)}`, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });

            return response.json().catch(() => null);
        }

        async function finishMidtransPayment(orderId, redirectUrl) {
            let syncResult = null;

            try {
                syncResult = await syncMidtransOrder(orderId);
            } finally {
                const target = redirectUrl === '/licenses' && syncResult?.status !== 'paid' ? '/orders' : redirectUrl;
                window.location.href = target;
            }
        }

        function openMidtransPayment(token, orderId) {
            if (!window.snap) {
                const target = new URL('/midtrans-pay', window.location.origin);
                target.searchParams.set('token', token);

                if (orderId) {
                    target.searchParams.set('order_id', orderId);
                }

                window.location.href = target.toString();
                return;
            }

            window.snap.pay(token, {
                onSuccess: function(result) {
                    finishMidtransPayment(orderId || result?.order_id, '/licenses');
                },
                onPending: function(result) {
                    finishMidtransPayment(orderId || result?.order_id, '/orders');
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
            btn.innerText = isAuthenticated ? 'Pay Now' : 'Login to Pay';
            btn.classList.remove('opacity-60', 'bg-gray-500', 'cursor-not-allowed', 'pointer-events-none');
        }

        /* =========================
           PAY BUTTON
        ========================= */
        document.getElementById('payMainBtn').onclick = async function() {

            if (this.disabled) return;

            if (!isAuthenticated) {
                requireLogin();
                return;
            }

            if (!selectedPackageId) {
                showToast('Select package', 'Select a package first.', null, 'warning');
                return;
            }

            if (selectedPackageStock <= 0) {
                showToast('Out of stock', 'This package is out of stock.', null, 'warning');
                return;
            }

            if (!selectedPayment) {
                showToast('Select payment', 'Select a payment method.', null, 'warning');
                return;
            }

            if (selectedPayment === 'crypto' && !selectedCoin) {
                showToast('Select network', 'Select a crypto network first.', null, 'warning');
                return;
            }

            showToast('Checkout started', 'Preparing your payment link.');

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
                    openMidtransPayment(data.snap_token, data.order_id);
                } catch (error) {
                    if (error.redirectUrl) {
                        window.location.href = error.redirectUrl;
                        return;
                    }

                    if (error.status === 401) {
                        requireLogin();
                        return;
                    }

                    showToast('Payment failed', error.message || 'Payment failed', null, 'error');
                    resetPayButton();
                }
            }

            if (selectedPayment === 'crypto') {

                let min = networkMinimum(selectedCoin);

                if (selectedUsd < min) {
                    showToast('Minimum payment', `Minimum payment for ${selectedCoin} is $${min}.`, null, 'warning');

                    this.disabled = false
                    this.innerText = isAuthenticated ? "Pay Now" : "Login to Pay"
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

                    if (error.status === 401) {
                        requireLogin();
                        return;
                    }

                    showToast('Payment failed', error.message || 'Payment failed', null, 'error');
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
                btn.innerText = isAuthenticated ? "Pay Now" : "Login to Pay"
                btn.classList.remove('opacity-60', 'bg-gray-500', 'cursor-not-allowed', 'pointer-events-none')
            }
        });
    </script>
@endsection
