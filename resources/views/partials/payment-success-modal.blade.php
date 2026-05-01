<div id="aksaPaymentSuccessModal" class="qris-modal hidden" aria-hidden="true">
    <div class="qris-modal-backdrop" data-payment-success-close></div>

    <section class="qris-dialog payment-success-dialog" role="dialog" aria-modal="true" aria-labelledby="aksaPaymentSuccessTitle">
        <div class="flex justify-end">
            <button type="button" class="qris-close-button" data-payment-success-close aria-label="Close payment success">x</button>
        </div>

        <div class="text-center">
            <div class="payment-success-mark mx-auto" aria-hidden="true">
                <span class="payment-success-check"></span>
            </div>

            <p class="mt-5 text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Payment Complete</p>
            <h2 id="aksaPaymentSuccessTitle" class="mt-1 text-2xl font-semibold text-white">Payment Successful</h2>
            <p id="aksaPaymentSuccessMessage" class="mt-3 text-sm leading-6 text-gray-400">
                Your payment has been verified and your license is ready.
            </p>
            <p id="aksaPaymentSuccessCopyStatus" class="mt-3 text-xs font-semibold text-[#D8B4FE]">
                Copying license key...
            </p>
            <p id="aksaPaymentSuccessCountdown" class="mt-2 text-xs text-gray-500">
                Redirecting to My Licenses in 5s.
            </p>
        </div>

        <div class="mt-5">
            <a href="/licenses" id="aksaPaymentSuccessPrimary" class="order-action w-full">
                View License
            </a>
        </div>
    </section>
</div>
