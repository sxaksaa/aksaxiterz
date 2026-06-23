<div id="aksaBinancePayModal" class="qris-modal hidden" aria-hidden="true">
    <div class="qris-modal-backdrop" data-binance-pay-close></div>

    <section class="qris-dialog" role="dialog" aria-modal="true" aria-labelledby="aksaBinancePayTitle">
        <div class="flex items-start justify-between gap-4">
            <div class="flex min-w-0 items-start gap-3">
                <span class="payment-card-icon mt-0.5 text-[#F0B90B]">
                    <x-ui.icon name="binance" class="h-5 w-5" />
                </span>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Binance user-to-user payment</p>
                    <h2 id="aksaBinancePayTitle" class="mt-1 text-xl font-semibold text-white">Pay with Binance</h2>
                </div>
            </div>
            <button type="button" class="qris-close-button" data-binance-pay-close aria-label="Close Binance Pay checkout">x</button>
        </div>

        <div id="aksaBinancePayExpiredNotice" class="crypto-expired-notice mt-5 hidden" role="alert">
            <p class="text-sm font-semibold text-red-200">Payment Window Expired</p>
            <p class="mt-1 text-xs leading-5 text-gray-300">
                Do not send a new payment for this invoice. Start a new checkout instead.
            </p>
        </div>

        <div id="aksaBinancePayDetails">
            <div class="crypto-payment-warning mt-5">
                <p class="text-[11px] font-semibold uppercase tracking-normal text-white">Important</p>
                <p class="mt-1 text-sm font-semibold leading-5 text-[#F5D0FE]">
                    Send the exact amount shown. Use Binance Pay, not an on-chain withdrawal.
                </p>
                <p class="mt-1 text-xs leading-5 text-gray-300">
                    Open Binance, choose Pay or Send, enter the Pay ID below, select the displayed token, and paste the exact amount.
                </p>
            </div>

            <div id="aksaBinancePayQrWrap" class="qris-canvas-wrap mt-5 hidden">
                <canvas id="aksaBinancePayCanvas" width="256" height="256" aria-label="Binance Pay receive code"></canvas>
            </div>

            <div class="mt-3 grid gap-3 text-sm">
                <div class="qris-detail-row qris-total-row">
                    <span>Amount</span>
                    <div class="flex min-w-0 items-center gap-2">
                        <span id="aksaBinancePayAmount" class="font-mono text-xs font-semibold text-[#D8B4FE]">-</span>
                        <button type="button" id="aksaBinancePayCopyAmount" class="order-action shrink-0 px-2 py-1 text-[11px]" data-copy-value="">
                            <x-ui.icon name="copy" class="h-3.5 w-3.5" />
                            <span data-button-label>Copy</span>
                        </button>
                    </div>
                </div>
                <div class="qris-detail-row crypto-address-row">
                    <span>Binance Pay ID</span>
                    <div class="flex min-w-0 items-center gap-2">
                        <span id="aksaBinancePayId" class="truncate font-mono text-xs text-gray-300">-</span>
                        <button type="button" id="aksaBinancePayCopyId" class="order-action shrink-0 px-2 py-1 text-[11px]" data-copy-value="">
                            <x-ui.icon name="copy" class="h-3.5 w-3.5" />
                            <span data-button-label>Copy</span>
                        </button>
                    </div>
                </div>
                <div class="qris-detail-row">
                    <span>Order ID</span>
                    <span id="aksaBinancePayOrderId" class="font-mono text-xs text-gray-300">-</span>
                </div>
                <div class="qris-detail-row">
                    <span>Time remaining</span>
                    <span id="aksaBinancePayExpires" class="text-right font-mono text-xs text-[#D8B4FE]">-</span>
                </div>
            </div>
        </div>

        <div class="mt-5 grid gap-2 sm:grid-cols-2">
            <button type="button" id="aksaBinancePayCheck" data-binance-pay-check class="order-action w-full">
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
