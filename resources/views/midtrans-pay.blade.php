<script
    src="{{ config('midtrans.isProduction') ? 'https://app.midtrans.com/snap/snap.js' : 'https://app.sandbox.midtrans.com/snap/snap.js' }}"
    data-client-key="{{ config('midtrans.clientKey') }}">
</script>

<script>
    window.snap.pay("{{ $snapToken }}", {
        onSuccess: function() {
            window.location.href = "/success";
        },
        onPending: function() {
            window.location.href = "/orders";
        },
        onError: function() {
            window.location.href = "/orders";
        }
    });
</script>
