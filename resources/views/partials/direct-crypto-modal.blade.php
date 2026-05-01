<div id="aksaCryptoModal" class="qris-modal hidden" aria-hidden="true">
    <div class="qris-modal-backdrop" data-crypto-close></div>

    <section class="qris-dialog" role="dialog" aria-modal="true" aria-labelledby="aksaCryptoTitle">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">USDT Address</p>
                <h2 id="aksaCryptoTitle" class="mt-1 text-xl font-semibold text-white">Send Exact Amount</h2>
            </div>
            <button type="button" class="qris-close-button" data-crypto-close aria-label="Close crypto checkout">x</button>
        </div>

        <div class="mt-5 grid gap-3 text-sm">
            <div class="qris-detail-row">
                <span>Network</span>
                <span id="aksaCryptoNetwork" class="font-semibold text-gray-200">-</span>
            </div>
            <div class="qris-detail-row qris-total-row">
                <span>Amount</span>
                <div class="flex min-w-0 items-center gap-2">
                    <span id="aksaCryptoAmount" class="font-mono text-xs font-semibold text-[#D8B4FE]">-</span>
                    <button type="button" id="aksaCryptoCopyAmount" class="order-action shrink-0 px-2 py-1 text-[11px]" data-copy-value="">
                        Copy
                    </button>
                </div>
            </div>
            <div class="qris-detail-row crypto-address-row">
                <span>Address</span>
                <div class="flex min-w-0 items-center gap-2">
                    <span id="aksaCryptoAddress" class="truncate font-mono text-xs text-gray-300">-</span>
                    <button type="button" id="aksaCryptoCopyAddress" class="order-action shrink-0 px-2 py-1 text-[11px]" data-copy-value="">
                        Copy
                    </button>
                </div>
            </div>
            <div class="qris-detail-row crypto-address-row">
                <span>Contract</span>
                <span id="aksaCryptoContract" class="min-w-0 truncate text-right font-mono text-[11px] text-gray-500">-</span>
            </div>
            <div class="qris-detail-row">
                <span>Order ID</span>
                <span id="aksaCryptoOrderId" class="font-mono text-xs text-gray-300">-</span>
            </div>
            <div class="qris-detail-row">
                <span>Expires</span>
                <span id="aksaCryptoExpires" class="text-right text-gray-300">-</span>
            </div>
        </div>

        <div class="mt-5 grid gap-2 sm:grid-cols-2">
            <button type="button" id="aksaCryptoCheck" data-crypto-check class="order-action w-full">
                Check Payment
            </button>
            <a href="/orders" class="order-action w-full">
                Open Orders
            </a>
        </div>
    </section>
</div>
