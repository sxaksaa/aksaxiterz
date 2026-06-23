<div id="aksaQrisModal" class="qris-modal hidden" aria-hidden="true">
    <div class="qris-modal-backdrop" data-qris-close></div>

    <section class="qris-dialog" role="dialog" aria-modal="true" aria-labelledby="aksaQrisTitle">
        <div class="flex items-start justify-between gap-4">
            <div class="flex min-w-0 items-start gap-3">
                <span class="payment-card-icon mt-0.5">
                    <x-ui.icon name="qr-code" class="h-5 w-5" />
                </span>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">QRIS for Indonesia & Malaysia-supported wallets</p>
                    <h2 id="aksaQrisTitle" class="mt-1 text-xl font-semibold text-white">Scan to Pay</h2>
                </div>
            </div>
            <button type="button" class="qris-close-button" data-qris-close aria-label="Close QRIS checkout">x</button>
        </div>

        <div id="aksaQrisCanvasWrap" class="qris-canvas-wrap mt-5">
            <canvas id="aksaQrisCanvas" width="256" height="256" aria-label="QRIS payment code"></canvas>
            <div id="aksaQrisExpiredOverlay" class="qris-expired-overlay hidden">
                <strong>QRIS Expired</strong>
                <span>This payment code is being closed. Start a new checkout to pay.</span>
            </div>
        </div>

        <div class="mt-5 grid gap-3 text-sm">
            <div class="qris-detail-row">
                <span>Order ID</span>
                <span id="aksaQrisOrderId" class="font-mono text-xs text-gray-300">-</span>
            </div>
            <div class="qris-detail-row">
                <span>Product amount</span>
                <span id="aksaQrisBaseAmount" class="font-semibold text-gray-200">-</span>
            </div>
            <div class="qris-detail-row">
                <span>QRIS fee</span>
                <span id="aksaQrisFee" class="font-semibold text-gray-200">-</span>
            </div>
            <div class="qris-detail-row qris-total-row">
                <span>Total payment</span>
                <span id="aksaQrisAmount" class="qris-amount-value">-</span>
            </div>
            <div class="qris-detail-row">
                <span>Time remaining</span>
                <span id="aksaQrisExpires" class="text-right font-mono text-gray-300">-</span>
            </div>
        </div>

        <div class="mt-5">
            <button type="button" id="aksaQrisCheck" data-qris-check class="order-action w-full">
                <x-ui.icon name="refresh-cw" class="h-4 w-4" />
                <span data-button-label>Check Payment</span>
            </button>
        </div>
    </section>
</div>
