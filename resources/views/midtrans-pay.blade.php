<script
    src="{{ config('midtrans.isProduction') ? 'https://app.midtrans.com/snap/snap.js' : 'https://app.sandbox.midtrans.com/snap/snap.js' }}"
    data-client-key="{{ config('midtrans.clientKey') }}">
</script>

<script>
    const orderId = @json($orderId ?? null);
    const csrfToken = @json(csrf_token());

    async function syncMidtransOrder(id) {
        if (!id) return null;

        const response = await fetch(`/sync-midtrans-order/${encodeURIComponent(id)}`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
        });

        return response.json().catch(() => null);
    }

    async function finishMidtransPayment(result, redirectUrl) {
        let syncResult = null;

        try {
            syncResult = await syncMidtransOrder(orderId || result?.order_id);
        } finally {
            const target = redirectUrl === "/licenses" && syncResult?.status !== "paid" ? "/orders" : redirectUrl;
            window.location.href = target;
        }
    }

    window.snap.pay("{{ $token }}", {
        onSuccess: function(result) {
            finishMidtransPayment(result, "/licenses");
        },
        onPending: function(result) {
            finishMidtransPayment(result, "/orders");
        },
        onError: function() {
            window.location.href = "/orders";
        },
        onClose: function() {
            window.location.href = "/orders";
        }
    });
</script>
