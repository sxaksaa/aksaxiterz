@extends('layouts.app')

@section('content')
    <div class="page-shell public-account-page py-7 md:py-12">
        <section class="orders-hero account-hero fade-up mb-8">
            <div class="account-hero-layout">
                <div class="account-hero-copy">
                    <p class="account-eyebrow">Order Center</p>
                    <h1 class="account-title">Order History</h1>
                    <p class="account-copy">
                        Track payments, continue pending invoices, and jump back into your licenses after checkout.
                    </p>
                </div>
            </div>
        </section>

        @if (session('info'))
            <div class="mb-4 rounded-xl border border-yellow-500/30 bg-yellow-500/10 px-4 py-3 text-sm text-yellow-300">
                {{ session('info') }}
            </div>
        @endif

        <div id="ordersContent" class="fade-up">
            @include('partials.orders-list', ['orders' => $orders, 'orderStats' => $orderStats])
        </div>

    </div>

    @include('partials.pakasir-qris-modal')
    @include('partials.binance-pay-modal')
    @include('partials.direct-crypto-modal')
    @include('partials.payment-success-modal')

    <script nonce="{{ request()->attributes->get('csp_nonce') }}">
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        let lastPolledStatus = null;
        let ordersRefreshing = false;
        let orderStatusPolling = false;
        const ordersPageController = new AbortController();
        const ordersPageTimers = [];

        function trackOrdersInterval(callback, delay) {
            const timer = setInterval(callback, delay);
            ordersPageTimers.push(timer);

            return timer;
        }

        window.addEventListener('aksa:before-page-swap', () => {
            ordersPageController.abort();
            ordersPageTimers.forEach((timer) => clearInterval(timer));
        }, {
            once: true
        });

        function setButtonLabel(button, label) {
            const labelTarget = button?.querySelector('[data-button-label]');

            if (labelTarget) {
                labelTarget.textContent = label;
                return;
            }

            if (button) {
                button.innerText = label;
            }
        }

        function getButtonLabel(button) {
            return button?.querySelector('[data-button-label]')?.textContent || button?.innerText || '';
        }

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
            const ordersContent = document.getElementById('ordersContent');
            ordersContent?.classList.add('content-is-refreshing');

            try {
                const fragmentUrl = new URL('/orders-fragment', window.location.origin);
                fragmentUrl.search = window.location.search;

                const response = await fetch(fragmentUrl.toString(), {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) return;

                if (ordersContent) {
                    ordersContent.innerHTML = await response.text();
                }
                updateCountdowns();
            } finally {
                ordersRefreshing = false;
                ordersContent?.classList.remove('content-is-refreshing');
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

        async function syncBinancePayOrder(orderId) {
            if (!orderId) return null;

            const response = await fetch(`/sync-binance-pay-order/${encodeURIComponent(orderId)}`, {
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

        async function copyTextToClipboard(text) {
            if (!text) return false;

            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(text);
                return true;
            }

            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();

            try {
                return document.execCommand('copy');
            } finally {
                textarea.remove();
            }
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

            if (data.method === 'crypto' && data.crypto_payment) {
                const opened = await window.openAksaCryptoModal?.(data, {
                    startPolling: true,
                });

                if (!opened) {
                    await refreshOrders();
                }

                return;
            }

            if (data.method === 'binance_pay' && data.binance_pay_payment) {
                const opened = await window.openAksaBinancePayModal?.(data, {
                    startPolling: true,
                });

                if (!opened) {
                    await refreshOrders();
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
            const originalText = getButtonLabel(button);

            if (button) {
                button.disabled = true;
                setButtonLabel(button, 'Processing...');
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
                    setButtonLabel(button, originalText || 'Pay Again');
                    button.classList.remove('opacity-60', 'pointer-events-none');
                }
            }
        }, {
            signal: ordersPageController.signal
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
        }, {
            signal: ordersPageController.signal
        });

        document.addEventListener('click', async function(e) {
            const button = e.target.closest('.open-crypto-address-button');
            if (!button) return;

            e.preventDefault();

            let checkout = null;

            try {
                checkout = JSON.parse(button.dataset.cryptoCheckout || '{}');
            } catch (error) {
                checkout = null;
            }

            const opened = await window.openAksaCryptoModal?.(checkout, {
                startPolling: true,
            });

            if (!opened && checkout?.payment_url) {
                openHostedPayment(checkout.payment_url);
            }
        }, {
            signal: ordersPageController.signal
        });

        document.addEventListener('click', async function(e) {
            const button = e.target.closest('.open-binance-pay-button');
            if (!button) return;

            e.preventDefault();

            let checkout = null;

            try {
                checkout = JSON.parse(button.dataset.binancePayCheckout || '{}');
            } catch (error) {
                checkout = null;
            }

            await window.openAksaBinancePayModal?.(checkout, {
                startPolling: true,
            });
        }, {
            signal: ordersPageController.signal
        });

        document.addEventListener('click', async function(e) {
            const button = e.target.closest('[data-copy-order-id]');
            if (!button) return;

            e.preventDefault();

            const orderId = button.dataset.copyOrderId || '';

            try {
                await copyTextToClipboard(orderId);
                window.showAppToast?.('Order ID copied', 'Paste it when contacting support.', {
                    variant: 'success',
                });
            } catch (error) {
                window.showAppToast?.('Copy failed', 'Please select and copy the Order ID manually.', {
                    variant: 'error',
                });
            }
        }, {
            signal: ordersPageController.signal
        });

        document.addEventListener('submit', async function(e) {
            const form = e.target.closest('.sync-pakasir-form');
            if (!form) return;

            e.preventDefault();

            const button = form.querySelector('.sync-pakasir-button');
            const orderId = button?.dataset.orderId;
            const originalText = getButtonLabel(button);

            button.disabled = true;
            setButtonLabel(button, 'Checking...');
            button.classList.add('opacity-60', 'pointer-events-none');

            window.showAppToast?.('Payment check', 'Checking your QRIS payment via Pakasir.');

            try {
                const result = await syncPakasirOrder(orderId);

                if (result?.status === 'paid') {
                    window.showAksaPaymentSuccess?.({
                        message: 'Your QRIS payment has been verified and your license is ready.',
                        licenseKey: result.license_key,
                        licenseKeys: result.license_keys,
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
                setButtonLabel(button, originalText || 'Check Payment');
                button.classList.remove('opacity-60', 'pointer-events-none');
            }
        }, {
            signal: ordersPageController.signal
        });

        document.addEventListener('submit', async function(e) {
            const form = e.target.closest('.sync-crypto-form');
            if (!form) return;

            e.preventDefault();

            const button = form.querySelector('.sync-crypto-button');
            const orderId = button?.dataset.orderId;
            const originalText = getButtonLabel(button);

            button.disabled = true;
            setButtonLabel(button, 'Verifying...');
            button.classList.add('opacity-60', 'pointer-events-none');

            window.showAppToast?.('Payment check', 'Scanning the selected crypto network.');

            try {
                const result = await syncCryptoOrder(orderId);

                if (result?.status === 'paid') {
                    const deliveryPending = result?.delivery_pending === true;

                    window.showAksaPaymentSuccess?.({
                        message: result.message || 'Your crypto payment has been verified and your license is ready.',
                        licenseKey: result.license_key,
                        licenseKeys: result.license_keys,
                        orderId: result.order_id || orderId,
                        primaryUrl: deliveryPending ? '/orders' : undefined,
                        primaryText: deliveryPending ? 'Open Orders' : undefined,
                        copyStatusText: deliveryPending ? 'Support will deliver this license manually.' : undefined,
                        redirectDelay: deliveryPending ? 8000 : undefined,
                    }) || window.showAppToast?.('Payment successful', deliveryPending ? 'Payment verified. Manual delivery needed.' : 'Your license is ready.', {
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
                setButtonLabel(button, originalText || 'Verify');
                button.classList.remove('opacity-60', 'pointer-events-none');
            }
        }, {
            signal: ordersPageController.signal
        });

        document.addEventListener('submit', async function(e) {
            const form = e.target.closest('.sync-binance-pay-form');
            if (!form) return;

            e.preventDefault();

            const button = form.querySelector('.sync-binance-pay-button');
            const orderId = button?.dataset.orderId;
            const originalText = getButtonLabel(button);

            button.disabled = true;
            setButtonLabel(button, 'Checking...');
            button.classList.add('opacity-60', 'pointer-events-none');

            window.showAppToast?.('Payment check', 'Checking your Binance Pay transfer.');

            try {
                const result = await syncBinancePayOrder(orderId);

                if (result?.status === 'paid') {
                    const deliveryPending = result?.delivery_pending === true;

                    window.showAksaPaymentSuccess?.({
                        message: result.message || 'Your Binance Pay transfer has been verified and your license is ready.',
                        licenseKey: result.license_key,
                        licenseKeys: result.license_keys,
                        orderId: result.order_id || orderId,
                        primaryUrl: deliveryPending ? '/orders' : undefined,
                        primaryText: deliveryPending ? 'Open Orders' : undefined,
                        copyStatusText: deliveryPending ? 'Support will deliver this license manually.' : undefined,
                        redirectDelay: deliveryPending ? 8000 : undefined,
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
                setButtonLabel(button, originalText || 'Check Payment');
                button.classList.remove('opacity-60', 'pointer-events-none');
            }
        }, {
            signal: ordersPageController.signal
        });

        document.addEventListener('submit', async function(e) {
            const form = e.target.closest('.cancel-order-form');
            if (!form) return;

            e.preventDefault();

            const button = form.querySelector('.cancel-order-button');
            const originalText = getButtonLabel(button);

            if (button) {
                button.disabled = true;
                setButtonLabel(button, 'Cancelling...');
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
                    setButtonLabel(button, originalText || 'Cancel');
                    button.classList.remove('opacity-60', 'pointer-events-none');
                }
            }
        }, {
            signal: ordersPageController.signal
        });

        trackOrdersInterval(async () => {
            if (orderStatusPolling || document.hidden) return;

            orderStatusPolling = true;

            try {
                const response = await fetch('/check-order');
                const data = await response.json();

                if (!data.status) return;

                const qrisModalOpen = document.getElementById('aksaQrisModal')?.getAttribute('aria-hidden') === 'false';
                const cryptoModalOpen = document.getElementById('aksaCryptoModal')?.getAttribute('aria-hidden') === 'false';
                const binancePayModalOpen = document.getElementById('aksaBinancePayModal')?.getAttribute('aria-hidden') === 'false';

                if (data.status === 'pending' && data.payment_method === 'pakasir' && data.order_id && !qrisModalOpen) {
                    const result = await syncPakasirOrder(data.order_id);

                    if (result?.status === 'paid') {
                        window.showAksaPaymentSuccess?.({
                            message: 'Your QRIS payment has been verified and your license is ready.',
                            licenseKey: result.license_key,
                            licenseKeys: result.license_keys,
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
                }

                if (data.can_sync_crypto && data.payment_method === 'crypto' && data.order_id && !cryptoModalOpen) {
                    const result = await syncCryptoOrder(data.order_id);

                    if (result?.status === 'paid') {
                        const deliveryPending = result?.delivery_pending === true;

                        window.showAksaPaymentSuccess?.({
                            message: result.message || 'Your crypto payment has been verified and your license is ready.',
                            licenseKey: result.license_key,
                            licenseKeys: result.license_keys,
                            orderId: result.order_id || data.order_id,
                            primaryUrl: deliveryPending ? '/orders' : undefined,
                            primaryText: deliveryPending ? 'Open Orders' : undefined,
                            copyStatusText: deliveryPending ? 'Support will deliver this license manually.' : undefined,
                            redirectDelay: deliveryPending ? 8000 : undefined,
                        }) || window.showAppToast?.('Payment successful', deliveryPending ? 'Payment verified. Manual delivery needed.' : 'Your license is ready.', {
                            variant: 'success',
                        });
                        refreshOrders();
                        return;
                    }

                    if (result?.status && result.status !== lastPolledStatus) {
                        lastPolledStatus = result.status;
                        refreshOrders();
                    }
                }

                if (data.can_sync_binance_pay && data.payment_method === 'binance_pay' && data.order_id && !binancePayModalOpen) {
                    const result = await syncBinancePayOrder(data.order_id);

                    if (result?.status === 'paid') {
                        const deliveryPending = result?.delivery_pending === true;

                        window.showAksaPaymentSuccess?.({
                            message: result.message || 'Your Binance Pay transfer has been verified and your license is ready.',
                            licenseKey: result.license_key,
                            licenseKeys: result.license_keys,
                            orderId: result.order_id || data.order_id,
                            primaryUrl: deliveryPending ? '/orders' : undefined,
                            primaryText: deliveryPending ? 'Open Orders' : undefined,
                            copyStatusText: deliveryPending ? 'Support will deliver this license manually.' : undefined,
                            redirectDelay: deliveryPending ? 8000 : undefined,
                        });
                        refreshOrders();
                        return;
                    }

                    if (result?.status && result.status !== lastPolledStatus) {
                        lastPolledStatus = result.status;
                        refreshOrders();
                    }
                }

                if (data.status !== lastPolledStatus) {
                    lastPolledStatus = data.status;

                    if (data.status !== 'pending') {
                        refreshOrders();
                    }
                }
            } catch (error) {
                // Polling is best-effort; manual payment checks remain available.
            } finally {
                orderStatusPolling = false;
            }
        }, 15000);

        function updateCountdowns() {
            document.querySelectorAll('.countdown').forEach(el => {
                if (!el.dataset.deadline) {
                    const remaining = Math.max(0, Number(el.dataset.remaining || 0));
                    el.dataset.deadline = String(performance.now() + remaining * 1000);
                }

                const diff = Number(el.dataset.deadline) - performance.now();

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

        trackOrdersInterval(updateCountdowns, 1000);

        document.addEventListener('DOMContentLoaded', function() {
            showPaymentNoticeFromQuery();
            updateCountdowns();
        }, {
            signal: ordersPageController.signal
        });

        window.addEventListener('pageshow', function() {
            refreshOrders();
        }, {
            signal: ordersPageController.signal
        });
    </script>
@endsection
