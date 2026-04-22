<script src="https://app.sandbox.midtrans.com/snap/snap.js"
data-client-key="{{ config('midtrans.clientKey') }}"></script>

<script>
window.snap.pay("{{ $snapToken }}", {
    onSuccess: function(){
        window.location.href = "/success";
    },
    onPending: function(){
        alert("Pending 😅");
    },
    onError: function(){
        alert("Error 😢");
    }
});
</script>