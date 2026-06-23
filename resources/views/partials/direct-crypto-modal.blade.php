<div id="aksaCryptoModal" class="qris-modal hidden" aria-hidden="true">
    <div class="qris-modal-backdrop" data-crypto-close></div>

    <section class="qris-dialog" role="dialog" aria-modal="true" aria-labelledby="aksaCryptoTitle">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 id="aksaCryptoTitle" class="text-xl font-semibold text-white">Crypto Payment</h2>
            </div>
            <button type="button" class="qris-close-button" data-crypto-close aria-label="Close crypto checkout">x</button>
        </div>

        <div id="aksaCryptoExpiredNotice" class="crypto-expired-notice mt-5 hidden" role="alert">
            <p class="text-sm font-semibold text-red-200">Payment Window Expired</p>
            <p class="mt-1 text-xs leading-5 text-gray-300">
                Do not send a new payment to this invoice. Start a new checkout to pay.
            </p>
            <p class="mt-2 text-xs font-semibold leading-5 text-white">
                Already sent it before expiry? Use Verify Already Sent below.
            </p>
        </div>

        <div id="aksaCryptoPaymentDetails" class="mt-5 grid gap-3 text-sm">
            <div class="crypto-payment-warning">
                <p class="text-[11px] font-semibold uppercase tracking-normal text-white">Important</p>
                <p class="mt-1 text-sm font-semibold leading-5 text-[#F5D0FE]">
                    Send exactly the amount shown below. Network/exchange fee is not included.
                </p>
                <p class="mt-1 text-xs leading-5 text-gray-300">
                    The received token amount must match this invoice amount, or the order will stay pending.
                </p>
                <p class="mt-2 text-xs font-semibold leading-5 text-white">
                    Use the selected network and keep your wallet receipt. If Binance shows Off-chain Transfer, keep that receipt too.
                </p>
            </div>
            <div class="qris-detail-row">
                <span>Network</span>
                <span id="aksaCryptoNetwork" class="font-semibold text-gray-200">-</span>
            </div>
            <div class="qris-detail-row qris-total-row">
                <span>Amount</span>
                <div class="flex min-w-0 items-center gap-2">
                    <span id="aksaCryptoAmount" class="font-mono text-xs font-semibold text-[#D8B4FE]">-</span>
                    <button type="button" id="aksaCryptoCopyAmount" class="order-action shrink-0 px-2 py-1 text-[11px]" data-copy-value="">
                        <x-ui.icon name="copy" class="h-3.5 w-3.5" />
                        <span data-button-label>Copy</span>
                    </button>
                </div>
            </div>
            <div class="qris-detail-row crypto-address-row">
                <span>Address</span>
                <div class="flex min-w-0 items-center gap-2">
                    <span id="aksaCryptoAddress" class="truncate font-mono text-xs text-gray-300">-</span>
                    <button type="button" id="aksaCryptoCopyAddress" class="order-action shrink-0 px-2 py-1 text-[11px]" data-copy-value="">
                        <x-ui.icon name="copy" class="h-3.5 w-3.5" />
                        <span data-button-label>Copy</span>
                    </button>
                </div>
            </div>
            <div class="qris-detail-row crypto-address-row">
                <span>Token contract</span>
                <span id="aksaCryptoContract" class="min-w-0 truncate text-right font-mono text-[11px] text-gray-500">-</span>
            </div>
        </div>

        <div class="mt-3 grid gap-3 text-sm">
            <div class="qris-detail-row">
                <span>Order ID</span>
                <span id="aksaCryptoOrderId" class="font-mono text-xs text-gray-300">-</span>
            </div>
            <div class="qris-detail-row">
                <span>Expires</span>
                <span id="aksaCryptoExpires" class="text-right font-mono text-xs text-[#D8B4FE]" data-expire="">-</span>
            </div>
        </div>

        <div class="mt-5 grid gap-2 sm:grid-cols-2">
            <button type="button" id="aksaCryptoCheck" data-crypto-check class="order-action w-full">
                <x-ui.icon name="refresh-cw" class="h-4 w-4" />
                <span data-button-label>Check Payment</span>
            </button>
            <a href="/orders" class="order-action w-full">
                <x-ui.icon name="receipt" class="h-4 w-4" />
                <span>Open Orders</span>
            </a>
        </div>
    </section>
</div>
