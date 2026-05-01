<div id="aksaQrisModal" class="qris-modal hidden" aria-hidden="true">
    <div class="qris-modal-backdrop" data-qris-close></div>

    <section class="qris-dialog" role="dialog" aria-modal="true" aria-labelledby="aksaQrisTitle">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">QRIS for Indonesia & Malaysia-supported wallets</p>
                <h2 id="aksaQrisTitle" class="mt-1 text-xl font-semibold text-white">Scan to Pay</h2>
            </div>
            <button type="button" class="qris-close-button" data-qris-close aria-label="Close QRIS checkout">x</button>
        </div>

        <div class="qris-canvas-wrap mt-5">
            <canvas id="aksaQrisCanvas" width="256" height="256" aria-label="QRIS payment code"></canvas>
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
                <span id="aksaQrisAmount" class="font-semibold text-[#D8B4FE]">-</span>
            </div>
            <div class="qris-detail-row">
                <span>Expires</span>
                <span id="aksaQrisExpires" class="text-right text-gray-300">-</span>
            </div>
        </div>

        <div class="mt-5">
            <button type="button" id="aksaQrisCheck" data-qris-check class="order-action w-full">
                Check Payment
            </button>
        </div>
    </section>
</div>
