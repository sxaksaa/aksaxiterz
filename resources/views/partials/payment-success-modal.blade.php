<div id="aksaPaymentSuccessModal" class="qris-modal hidden" aria-hidden="true" inert>
    <div class="qris-modal-backdrop" data-payment-success-close></div>

    <section class="qris-dialog payment-success-dialog" role="dialog" aria-modal="true" aria-labelledby="aksaPaymentSuccessTitle">
        <div class="flex justify-end" data-payment-success-stage="close">
            <button type="button" class="qris-close-button" data-payment-success-close aria-label="Close payment success">x</button>
        </div>

        <div class="text-center">
            <div class="payment-success-mark mx-auto" data-payment-success-stage="mark" aria-hidden="true">
                <svg class="payment-success-check" viewBox="0 0 32 32" focusable="false">
                    <path d="M7 17l6 6L25 10" pathLength="1"></path>
                </svg>
            </div>

            <p class="mt-5 text-xs font-semibold uppercase tracking-normal text-aksa-accent" data-payment-success-stage="eyebrow">Payment Complete</p>
            <h2 id="aksaPaymentSuccessTitle" class="mt-1 text-2xl font-semibold text-white" data-payment-success-stage="title">Payment Successful</h2>
            <p id="aksaPaymentSuccessMessage" class="mt-3 text-sm leading-6 text-gray-400" data-payment-success-stage="message">
                Your payment has been verified and your license is ready.
            </p>
            <p id="aksaPaymentSuccessCopyStatus" class="mt-3 text-xs font-semibold text-aksa-accent-soft" data-payment-success-stage="status">
                Copying license key...
            </p>
            <p id="aksaPaymentSuccessCountdown" class="mt-2 text-xs text-gray-500" data-payment-success-stage="countdown">
                Redirecting to My Licenses in 5s.
            </p>
        </div>

        <div class="mt-5" data-payment-success-stage="action">
            <a href="/licenses" id="aksaPaymentSuccessPrimary" class="order-action w-full">
                View License
            </a>
        </div>
    </section>
</div>
