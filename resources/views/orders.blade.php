@extends('layouts.app')

@push('head')
    <script
        src="{{ config('midtrans.isProduction') ? 'https://app.midtrans.com/snap/snap.js' : 'https://app.sandbox.midtrans.com/snap/snap.js' }}"
        data-client-key="{{ config('midtrans.clientKey') }}">
    </script>
@endpush

@section('content')
    <div class="max-w-5xl mx-auto px-4 sm:px-6 md:px-12 py-6 md:py-10">

        <h1 class="text-xl sm:text-2xl font-semibold mb-6">Riwayat Order</h1>

        @if (session('info'))
            <div class="mb-4 px-4 py-3 rounded-lg bg-yellow-500/10 border border-yellow-500/30 text-yellow-300">
                {{ session('info') }}
            </div>
        @endif

        <div id="ordersContent">
            @include('partials.orders-list', ['orders' => $orders])
        </div>

    </div>

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
                const error = new Error(data.message || 'Payment failed');
                error.redirectUrl = data.redirect_url;
                throw error;
            }

            return data;
        }

        async function refreshOrders() {
            if (ordersRefreshing) return;

            ordersRefreshing = true;

            try {
                const response = await fetch('/orders-fragment', {
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
                    refreshOrders();
                },
            });
        }

        function handlePaymentResponse(data) {
            if (data.redirect_url) {
                window.location.href = data.redirect_url;
                return;
            }

            if (data.method === 'midtrans' && data.snap_token) {
                openMidtransPayment(data.snap_token);
                return;
            }

            if (data.method === 'crypto' && data.payment_url) {
                const invoiceTab = window.open(data.payment_url, '_blank', 'noopener');

                if (!invoiceTab) {
                    window.location.href = data.payment_url;
                    return;
                }

                refreshOrders();
            }
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

            try {
                const data = await fetchPaymentJson(form.action, new FormData(form));
                await refreshOrders();
                handlePaymentResponse(data);
            } catch (error) {
                if (error.redirectUrl) {
                    window.location.href = error.redirectUrl;
                    return;
                }

                alert(error.message || 'Payment failed');
                await refreshOrders();
            } finally {
                if (button) {
                    button.disabled = false;
                    button.innerText = originalText || 'Pay Again';
                    button.classList.remove('opacity-60', 'pointer-events-none');
                }
            }
        });

        setInterval(() => {
            fetch('/check-order')
                .then(res => res.json())
                .then(data => {
                    if (!data.status) return;

                    if (data.status !== lastPolledStatus) {
                        lastPolledStatus = data.status;

                        if (data.status !== 'pending') {
                            refreshOrders();
                        }
                    }
                });
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

        window.addEventListener('pageshow', function() {
            refreshOrders();
        });
    </script>
@endsection
