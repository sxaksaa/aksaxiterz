@extends('layouts.app')

@section('content')
    @php
        $totalOrders = $orderStats['total'] ?? $orders->count();
        $paidOrders = $orderStats['paid'] ?? $orders->where('status', 'paid')->count();
        $pendingOrders = $orderStats['pending'] ?? $orders->where('status', 'pending')->count();
    @endphp

    <div class="page-shell py-6 md:py-10">
        <section class="orders-hero fade-up mb-6">
            <div class="grid gap-5 lg:grid-cols-[1fr_auto] lg:items-end">
                <div>
                    <p class="mb-2 text-sm font-semibold text-[#C084FC]">Order Center</p>
                    <h1 class="text-3xl font-bold tracking-normal md:text-4xl">Order History</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                        Track payments, continue pending invoices, and jump back into your licenses after checkout.
                    </p>
                </div>
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-3">
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $totalOrders }}</div>
                    <div class="mt-1 text-xs text-gray-400">Total orders</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $paidOrders }}</div>
                    <div class="mt-1 text-xs text-gray-400">Paid orders</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $pendingOrders }}</div>
                    <div class="mt-1 text-xs text-gray-400">Waiting payment</div>
                </div>
            </div>
        </section>

        @if (session('info'))
            <div class="mb-4 rounded-xl border border-yellow-500/30 bg-yellow-500/10 px-4 py-3 text-sm text-yellow-300">
                {{ session('info') }}
            </div>
        @endif

        <div id="ordersContent" class="fade-up">
            @include('partials.orders-list', ['orders' => $orders])
        </div>

    </div>

    @include('partials.pakasir-qris-modal')
    @include('partials.payment-success-modal')

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        let lastPolledStatus = null;
        let ordersRefreshing = false;

        async function fetchPaymentJson(url, formData) {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: formData,
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                const isTooManyAttempts = response.status === 429;
                const error = new Error(
                    isTooManyAttempts ?
                        (data.message || 'Too many payment attempts. Cancel unfinished payments from Orders first.') :
                        (data.message || 'Payment failed')
                );
                error.redirectUrl = data.redirect_url || (isTooManyAttempts ? '/orders?payment_notice=too-many-attempts' : null);
                throw error;
            }

            return data;
        }

        async function refreshOrders() {
            if (ordersRefreshing) return;

            ordersRefreshing = true;

            try {
                const fragmentUrl = new URL('/orders-fragment', window.location.origin);
                fragmentUrl.search = window.location.search;

                const response = await fetch(fragmentUrl.toString(), {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) return;

                document.getElementById('ordersContent').innerHTML = await response.text();
                updateCountdowns();
            } finally {
                ordersRefreshing = false;
            }
        }

        async function syncPakasirOrder(orderId) {
            if (!orderId) return null;

            const response = await fetch(`/sync-pakasir-order/${encodeURIComponent(orderId)}`, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok && response.status !== 202) {
                const error = new Error(data.error || data.message || `Payment check failed (${response.status})`);
                error.status = response.status;
                throw error;
            }

            return data;
        }

        async function syncCryptoOrder(orderId) {
            if (!orderId) return null;

            const response = await fetch(`/sync-crypto-order/${encodeURIComponent(orderId)}`, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok && response.status !== 202) {
                const error = new Error(data.error || data.message || `Payment check failed (${response.status})`);
                error.status = response.status;
                throw error;
            }

            return data;
        }

        function openHostedPayment(paymentUrl) {
            const paymentTab = window.open(paymentUrl, '_blank');

            if (paymentTab) {
                try {
                    paymentTab.opener = null;
                } catch (error) {}

                refreshOrders();
                return;
            }

            window.location.href = paymentUrl;
        }

        async function handlePaymentResponse(data) {
            if (data.redirect_url) {
                window.location.href = data.redirect_url;
                return;
            }

            if (data.method === 'pakasir' && data.payment_url) {
                const opened = await window.openAksaQrisModal?.(data);

                if (!opened) {
                    openHostedPayment(data.payment_url);
                }

                return;
            }

            if (data.method === 'crypto' && data.payment_url) {
                openHostedPayment(data.payment_url);
            }
        }

        function showPaymentNoticeFromQuery() {
            const url = new URL(window.location.href);
            const notice = url.searchParams.get('payment_notice');

            if (!notice) return;

            const messages = {
                'pending-order': [
                    'Unfinished payment',
                    'Cancel or continue your unfinished order before starting a new checkout.',
                    'warning',
                ],
                'too-many-attempts': [
                    'Too many attempts',
                    'Cancel unfinished payments here, then start checkout again.',
                    'warning',
                ],
            };

            const message = messages[notice];

            if (message) {
                window.showAppToast?.(message[0], message[1], {
                    variant: message[2],
                });
            }

            url.searchParams.delete('payment_notice');
            window.history.replaceState({}, '', url.pathname + url.search + url.hash);
        }

        document.addEventListener('submit', async function(e) {
            const form = e.target.closest('.pay-again-form');
            if (!form) return;

            e.preventDefault();

            const button = form.querySelector('button[type="submit"]');
            const originalText = button?.innerText;

            if (button) {
                button.disabled = true;
                button.innerText = 'Processing...';
                button.classList.add('opacity-60', 'pointer-events-none');
            }

            window.showAppToast?.('Payment retry', 'Preparing your payment link.');

            try {
                const data = await fetchPaymentJson(form.action, new FormData(form));
                await refreshOrders();
                await handlePaymentResponse(data);
            } catch (error) {
                if (error.redirectUrl) {
                    window.location.href = error.redirectUrl;
                    return;
                }

                window.showAppToast?.('Payment failed', error.message || 'Payment failed', {
                    variant: 'error',
                });
                await refreshOrders();
            } finally {
                if (button) {
                    button.disabled = false;
                    button.innerText = originalText || 'Pay Again';
                    button.classList.remove('opacity-60', 'pointer-events-none');
                }
            }
        });

        document.addEventListener('click', async function(e) {
            const button = e.target.closest('.open-pakasir-qris-button');
            if (!button) return;

            e.preventDefault();

            let checkout = null;

            try {
                checkout = JSON.parse(button.dataset.pakasirCheckout || '{}');
            } catch (error) {
                checkout = null;
            }

            const opened = await window.openAksaQrisModal?.(checkout);

            if (!opened && checkout?.payment_url) {
                openHostedPayment(checkout.payment_url);
            }
        });

        document.addEventListener('submit', async function(e) {
            const form = e.target.closest('.sync-pakasir-form');
            if (!form) return;

            e.preventDefault();

            const button = form.querySelector('.sync-pakasir-button');
            const orderId = button?.dataset.orderId;
            const originalText = button.innerText;

            button.disabled = true;
            button.innerText = 'Checking...';
            button.classList.add('opacity-60', 'pointer-events-none');

            window.showAppToast?.('Payment check', 'Checking your QRIS payment via Pakasir.');

            try {
                const result = await syncPakasirOrder(orderId);

                if (result?.status === 'paid') {
                    window.showAksaPaymentSuccess?.({
                        message: 'Your QRIS payment has been verified and your license is ready.',
                        licenseKey: result.license_key,
                        orderId: result.order_id || orderId,
                    }) || window.showAppToast?.('Payment successful', 'Your license is ready.', {
                        variant: 'success',
                    });
                    await refreshOrders();
                    return;
                }

                window.showAppToast?.('Still pending', result?.message || 'Payment is still being verified.', {
                    variant: 'warning',
                });
                await refreshOrders();
            } catch (error) {
                window.showAppToast?.('Payment check failed', error.message || 'Please try again in a moment.', {
                    variant: 'error',
                });
            } finally {
                button.disabled = false;
                button.innerText = originalText || 'Check Payment';
                button.classList.remove('opacity-60', 'pointer-events-none');
            }
        });

        document.addEventListener('submit', async function(e) {
            const form = e.target.closest('.sync-crypto-form');
            if (!form) return;

            e.preventDefault();

            const button = form.querySelector('.sync-crypto-button');
            const orderId = button?.dataset.orderId;
            const originalText = button.innerText;

            button.disabled = true;
            button.innerText = 'Verifying...';
            button.classList.add('opacity-60', 'pointer-events-none');

            window.showAppToast?.('Payment check', 'Checking your crypto payment via NOWPayments.');

            try {
                const result = await syncCryptoOrder(orderId);

                if (result?.status === 'paid') {
                    window.showAksaPaymentSuccess?.({
                        message: 'Your crypto payment has been verified and your license is ready.',
                        licenseKey: result.license_key,
                        orderId: result.order_id || orderId,
                    }) || window.showAppToast?.('Payment successful', 'Your license is ready.', {
                        variant: 'success',
                    });
                    await refreshOrders();
                    return;
                }

                window.showAppToast?.('Still verifying', result?.message || 'Payment is still being verified.', {
                    variant: 'warning',
                });
                await refreshOrders();
            } catch (error) {
                window.showAppToast?.('Payment check failed', error.message || 'Please try again in a moment.', {
                    variant: 'error',
                });
            } finally {
                button.disabled = false;
                button.innerText = originalText || 'Verify';
                button.classList.remove('opacity-60', 'pointer-events-none');
            }
        });

        document.addEventListener('submit', async function(e) {
            const form = e.target.closest('.cancel-order-form');
            if (!form) return;

            e.preventDefault();

            const button = form.querySelector('.cancel-order-button');
            const originalText = button?.innerText;

            if (button) {
                button.disabled = true;
                button.innerText = 'Cancelling...';
                button.classList.add('opacity-60', 'pointer-events-none');
            }

            window.showAppToast?.('Cancelling order', 'Closing this unfinished checkout.');

            try {
                const data = await fetchPaymentJson(form.action, new FormData(form));

                window.showAppToast?.('Order cancelled', data.message || 'You can start a new checkout now.', {
                    variant: 'success',
                });
                await refreshOrders();
            } catch (error) {
                window.showAppToast?.('Cancel failed', error.message || 'Please try again in a moment.', {
                    variant: 'error',
                });
            } finally {
                if (button) {
                    button.disabled = false;
                    button.innerText = originalText || 'Cancel';
                    button.classList.remove('opacity-60', 'pointer-events-none');
                }
            }
        });

        setInterval(() => {
            fetch('/check-order')
                .then(res => res.json())
                .then(data => {
                    if (!data.status) return;

                    if (data.status === 'pending' && data.payment_method === 'pakasir' && data.order_id) {
                        syncPakasirOrder(data.order_id).then(result => {
                            if (result?.status === 'paid') {
                                window.showAksaPaymentSuccess?.({
                                    message: 'Your QRIS payment has been verified and your license is ready.',
                                    licenseKey: result.license_key,
                                    orderId: result.order_id || data.order_id,
                                }) || window.showAppToast?.('Payment successful', 'Your license is ready.', {
                                    variant: 'success',
                                });
                                refreshOrders();
                                return;
                            }

                            if (result?.status && result.status !== lastPolledStatus) {
                                lastPolledStatus = result.status;
                                refreshOrders();
                            }
                        }).catch(() => {});
                    }

                    if (data.can_sync_crypto && data.payment_method === 'crypto' && data.order_id) {
                        syncCryptoOrder(data.order_id).then(result => {
                            if (result?.status === 'paid') {
                                window.showAksaPaymentSuccess?.({
                                    message: 'Your crypto payment has been verified and your license is ready.',
                                    licenseKey: result.license_key,
                                    orderId: result.order_id || data.order_id,
                                }) || window.showAppToast?.('Payment successful', 'Your license is ready.', {
                                    variant: 'success',
                                });
                                refreshOrders();
                                return;
                            }

                            if (result?.status && result.status !== lastPolledStatus) {
                                lastPolledStatus = result.status;
                                refreshOrders();
                            }
                        });
                    }

                    if (data.status !== lastPolledStatus) {
                        lastPolledStatus = data.status;

                        if (data.status !== 'pending') {
                            refreshOrders();
                        }
                    }
                })
                .catch(() => {});
        }, 5000);

        function updateCountdowns() {
            document.querySelectorAll('.countdown').forEach(el => {
                const expireTime = new Date(el.dataset.expire).getTime();
                const now = new Date().getTime();
                const diff = expireTime - now;

                if (diff <= 0) {
                    el.innerText = 'Expired';
                    el.classList.remove('text-yellow-400');
                    el.classList.add('text-red-400');
                    return;
                }

                const minutes = Math.floor(diff / 60000);
                const seconds = Math.floor((diff % 60000) / 1000);

                el.innerText = `${minutes}m ${seconds}s`;
            });
        }

        setInterval(updateCountdowns, 1000);

        document.addEventListener('DOMContentLoaded', function() {
            showPaymentNoticeFromQuery();
            updateCountdowns();
        });

        window.addEventListener('pageshow', function() {
            refreshOrders();
        });
    </script>
@endsection
