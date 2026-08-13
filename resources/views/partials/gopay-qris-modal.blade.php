<div id="aksaQrisModal" class="qris-modal hidden" aria-hidden="true" inert>
    <div class="qris-modal-backdrop" data-qris-close></div>

    <section class="qris-dialog" role="dialog" aria-modal="true" aria-labelledby="aksaQrisTitle">
        <div class="qris-dialog-header flex items-start justify-between gap-4">
            <div class="flex min-w-0 items-start gap-3">
                <span class="payment-card-icon mt-0.5">
                    <x-ui.icon name="qr-code" class="h-5 w-5" />
                </span>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">QRIS for Indonesia & Malaysia-supported wallets</p>
                    <h2 id="aksaQrisTitle" class="mt-1 text-xl font-semibold text-white">Scan to Pay</h2>
                </div>
            </div>
            <button type="button" class="qris-close-button" data-qris-close aria-label="Close QRIS checkout">x</button>
        </div>

        <div id="aksaQrisCanvasWrap" class="qris-canvas-wrap qris-canvas-wrap--styled mt-5">
            <div id="aksaQrisCanvas" class="qris-styled-target" role="img" aria-label="QRIS payment code"></div>
            <div id="aksaQrisExpiredOverlay" class="qris-expired-overlay hidden">
                <strong>QRIS Expired</strong>
                <span>Do not pay this expired QR. Start a new checkout to get a fresh amount.</span>
            </div>
        </div>

        <div class="crypto-payment-warning mt-4" role="alert">
            <p class="text-[11px] font-semibold uppercase tracking-normal text-white">Enter the amount manually</p>
            <p class="mt-1 text-xs leading-5 text-gray-300">
                1. Scan the QRIS. 2. Enter the exact total below. 3. Confirm the merchant name <strong class="text-white">Aksa Xiterz</strong>. A different amount cannot be verified automatically.
            </p>
        </div>

        <div id="aksaQrisAutoStatus" class="qris-auto-status mt-3" data-state="waiting" role="status" aria-live="polite">
            <span class="qris-auto-status-dot" aria-hidden="true"></span>
            <div class="min-w-0">
                <strong id="aksaQrisAutoStatusTitle">Automatic verification active</strong>
                <span id="aksaQrisAutoStatusMessage">We check this payment securely every 15 seconds.</span>
            </div>
        </div>

        <div class="mt-5 grid gap-3 text-sm">
            <div class="qris-detail-row">
                <span>Order ID</span>
                <span id="aksaQrisOrderId" class="font-mono text-xs text-gray-300">-</span>
            </div>
            <div class="qris-detail-row">
                <span>Order subtotal</span>
                <span id="aksaQrisBaseAmount" class="font-semibold text-gray-200">-</span>
            </div>
            <div class="qris-detail-row">
                <span>Platform fee (0.7%)</span>
                <span id="aksaQrisPlatformFee" class="font-semibold text-gray-200">-</span>
            </div>
            <div class="qris-detail-row">
                <span>Unique code</span>
                <span id="aksaQrisUniqueAmount" class="font-semibold text-gray-200">-</span>
            </div>
            <div class="qris-detail-row qris-total-row">
                <span>Exact amount to enter</span>
                <div class="flex min-w-0 items-center gap-2">
                    <span id="aksaQrisAmount" class="qris-amount-value">-</span>
                    <button
                        type="button"
                        id="aksaQrisCopyAmount"
                        class="order-action shrink-0 px-2 py-1 text-[11px]"
                        data-copy-value=""
                        data-copy-title="Amount copied"
                        data-copy-message="Enter this exact amount after scanning the static QRIS."
                    >
                        Copy
                    </button>
                </div>
            </div>
            <div class="qris-detail-row">
                <span>Time remaining</span>
                <span id="aksaQrisExpires" class="text-right font-mono text-gray-300">-</span>
            </div>
        </div>

        <div class="qris-dialog-footer mt-5">
            <button type="button" id="aksaQrisCheck" data-qris-check class="order-action w-full">
                <x-ui.icon name="refresh-cw" class="h-4 w-4" />
                <span data-button-label>Check Now</span>
            </button>
        </div>
    </section>
</div>
