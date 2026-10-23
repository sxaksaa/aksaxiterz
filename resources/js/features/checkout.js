export function initializeCheckout(root = document) {
    const form = root.matches?.('#checkoutForm') ? root : root.querySelector('#checkoutForm');
    if (!form || form.dataset.checkoutReady === 'true') return;
    form.dataset.checkoutReady = 'true';

    const { voucherUrl, checkoutMode, packageId, quantity, subtotalIdr, subtotalUsdt, checkoutAvailable, stablecoinPricingAvailable } = JSON.parse(form.dataset.checkoutConfig);
    let voucherQuote = null;
    let appliedVoucherCode = null;
    let voucherRequestController = null;
    let voucherRequestSequence = 0;
    let voucherRequestPending = false;
    let cryptoNetworkRefreshSequence = 0;
    let voucherMotionTimer = null;

    const formatIdr = value => `Rp ${Number(value).toLocaleString('id-ID')}`;
    const formatUsd = value => `$${Number(value).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 4,
    })}`;
    const formatStablecoin = (value, token) => `${Number(value).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 6,
    })} ${String(token || 'USD').toUpperCase()}`;

    const paymentMethod = () => form.querySelector('input[name="payment_method"]:checked')?.value || null;
    const selectedToken = () => form.querySelector('input[name="token"]:checked')?.value || null;
    const selectedCryptoToken = () => form.querySelector('input[name="crypto_token"]:checked')?.value || null;
    const selectedCoin = () => form.querySelector('input[name="coin"]:checked')?.value || null;
    const selectedStablecoin = () => paymentMethod() === 'binance_pay'
        ? selectedToken()
        : (paymentMethod() === 'crypto' ? selectedCoin() : null);
    const selectedPaymentToken = () => paymentMethod() === 'binance_pay'
        ? selectedToken()
        : (paymentMethod() === 'crypto' ? selectedCryptoToken() : null);

    function setCheckoutOptionsVisibility(element, visible) {
        if (!element) return;

        const wasVisible = element.classList.contains('is-open');
        element.classList.toggle('is-open', visible);
        element.setAttribute('aria-hidden', visible ? 'false' : 'true');
        element.inert = !visible;

        if (visible && !wasVisible) {
            element.classList.remove('is-switching');
            void element.offsetWidth;
            element.classList.add('is-switching');
            window.setTimeout(() => element.classList.remove('is-switching'), 520);
        }
    }

    function replayCheckoutSelectionMotion(element, className = 'is-selecting', duration = 420) {
        if (!element) return;

        element.classList.remove(className);
        void element.offsetWidth;
        element.classList.add(className);
        window.setTimeout(() => element.classList.remove(className), duration);
    }

    function setButtonLabel(button, label) {
        const target = button?.querySelector('[data-button-label]');
        if (target) target.textContent = label;
    }

    function revealFinalSummaryWhenReady() {
        if (!window.matchMedia('(max-width: 1023px)').matches) return;

        const method = paymentMethod();
        const selectionReady = method === 'gopay_qris' ||
            (method === 'binance_pay' && Boolean(selectedToken())) ||
            (method === 'crypto' && Boolean(selectedCoin()));

        if (!selectionReady) return;

        window.requestAnimationFrame(() => {
            document.getElementById('checkoutFinalSummary')?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
        });
    }

    function voucherFeedback(message, variant = 'success') {
        const element = document.getElementById('checkoutVoucherFeedback');
        element.textContent = message;
        element.classList.remove('hidden', 'text-aksa-accent', 'text-red-300', 'text-gray-400', 'voucher-feedback-in');
        element.classList.add(
            variant === 'success' ? 'text-aksa-accent' :
            (variant === 'loading' ? 'text-gray-400' : 'text-red-300')
        );

        if (variant !== 'loading') {
            void element.offsetWidth;
            element.classList.add('voucher-feedback-in');
        }
    }

    function resetVoucherMotion() {
        clearTimeout(voucherMotionTimer);
        const panel = document.getElementById('checkoutVoucherPanel');
        const input = document.getElementById('checkoutVoucherCode');
        const button = document.getElementById('checkoutApplyVoucher');

        panel?.classList.remove('is-voucher-applied');
        input?.classList.remove('is-voucher-applied');
        button?.classList.remove('is-voucher-applied');
    }

    function showVoucherAppliedMotion() {
        resetVoucherMotion();
        const panel = document.getElementById('checkoutVoucherPanel');
        const input = document.getElementById('checkoutVoucherCode');
        const button = document.getElementById('checkoutApplyVoucher');
        const discountRow = document.getElementById('checkoutDiscountRow');

        void panel?.offsetWidth;
        panel?.classList.add('is-voucher-applied');
        input?.classList.add('is-voucher-applied');
        button?.classList.add('is-voucher-applied');
        setButtonLabel(button, 'Applied');
        discountRow?.classList.remove('voucher-discount-in');
        if (discountRow) void discountRow.offsetWidth;
        discountRow?.classList.add('voucher-discount-in');

        voucherMotionTimer = setTimeout(() => {
            panel?.classList.remove('is-voucher-applied');
            discountRow?.classList.remove('voucher-discount-in');
        }, 1050);
    }

    function clearVoucherFeedback() {
        abortVoucherRequest();
        voucherQuote = null;
        appliedVoucherCode = null;
        document.getElementById('checkoutVoucherValue').value = '';
        document.getElementById('checkoutVoucherFeedback').classList.add('hidden');
        resetVoucherMotion();
        setButtonLabel(document.getElementById('checkoutApplyVoucher'), 'Apply');
    }

    function abortVoucherRequest() {
        voucherRequestSequence += 1;
        voucherRequestController?.abort();
        voucherRequestController = null;
        voucherRequestPending = false;
    }

    function handleVoucherRefreshError(error) {
        if (error?.name === 'AbortError') return;

        resetVoucherMotion();
        setButtonLabel(document.getElementById('checkoutApplyVoucher'), 'Apply');
        voucherFeedback(error.message || 'Voucher could not be applied.', 'error');
        updateTotals();
    }

    function refreshCryptoNetworkOptions() {
        const token = selectedCryptoToken();
        const networkOptions = document.getElementById('checkoutCryptoNetworkOptions');
        const prompt = document.getElementById('checkoutCryptoNetworkPrompt');
        const networkGrid = document.getElementById('checkoutCryptoNetworkGrid');
        const previousToken = networkGrid?.dataset.activeToken || '';
        const shouldSwap = Boolean(token) && Boolean(previousToken) && token !== previousToken && networkOptions?.classList.contains('is-open');
        const refreshSequence = ++cryptoNetworkRefreshSequence;

        setCheckoutOptionsVisibility(networkOptions, Boolean(token));

        if (prompt) {
            prompt.textContent = token ? `Choose a network for ${token.toUpperCase()}` : 'Select a coin first';
        }

        const applyNetworkOptions = () => {
            if (refreshSequence !== cryptoNetworkRefreshSequence) return;

            form.querySelectorAll('[data-checkout-crypto-network-option]').forEach(option => {
                const input = option.querySelector('input[name="coin"]');
                const matchesToken = option.dataset.token === token;

                option.classList.toggle('hidden', !matchesToken);
                input.disabled = !matchesToken;

                if (matchesToken) {
                    replayCheckoutSelectionMotion(option, 'is-network-entering', 520);
                } else {
                    option.classList.remove('is-network-entering');
                    input.checked = false;
                    option.classList.remove('active');
                }
            });

            if (networkGrid) networkGrid.dataset.activeToken = token || '';
            networkGrid?.classList.remove('is-swapping');
        };

        if (shouldSwap && networkGrid) {
            networkGrid.classList.add('is-swapping');
            window.setTimeout(applyNetworkOptions, 240);
        } else {
            applyNetworkOptions();
        }
    }

    function updateTotals() {
        const submitting = form.dataset.checkoutSubmitting === 'true';
        const method = paymentMethod();
        const stablecoin = method === 'crypto' || method === 'binance_pay';
        const stablecoinReady = stablecoin && Boolean(selectedStablecoin());
        const paymentToken = selectedPaymentToken();
        const qris = method === 'gopay_qris';
        const selectionReady = qris ||
            (method === 'binance_pay' && Boolean(selectedToken())) ||
            (method === 'crypto' && Boolean(selectedCoin()));
        const displayCurrency = window.getAksaDisplayCurrency?.() ||
            document.documentElement.dataset.displayCurrency ||
            'idr';
        const displaySubtotal = displayCurrency === 'usd'
            ? (stablecoinPricingAvailable ? formatUsd(subtotalUsdt) : 'USD unavailable')
            : formatIdr(subtotalIdr);
        const subtotal = stablecoin
            ? formatUsd(subtotalUsdt)
            : (qris ? formatIdr(subtotalIdr) : displaySubtotal);
        const total = stablecoin
            ? (stablecoinReady
                ? `${formatStablecoin(voucherQuote ? voucherQuote.final_usdt : subtotalUsdt, paymentToken)} + unique amount`
                : 'Complete coin selection')
            : (qris
                ? `${formatIdr(voucherQuote ? voucherQuote.final_idr : subtotalIdr)} base + platform fee + unique amount`
                : 'Select payment');
        const discount = stablecoin
            ? `-${formatStablecoin(voucherQuote?.discount_usdt || 0, paymentToken)}`
            : `-${formatIdr(voucherQuote?.discount_idr || 0)}`;

        document.getElementById('checkoutSubtotal').textContent = subtotal;
        document.getElementById('checkoutTotal').textContent = total;
        document.getElementById('checkoutSubtotalLabel').textContent =
            qris || stablecoin ? 'Catalog subtotal' : 'Subtotal';
        document.getElementById('checkoutTotalLabel').textContent =
            qris || stablecoin ? 'Invoice amount' : 'Total';
        document.getElementById('checkoutDiscount').textContent = discount;
        document.getElementById('checkoutDiscountRow').classList.toggle('hidden', !voucherQuote);

        const hint = document.getElementById('checkoutCurrencyHint');
        hint.textContent = qris
            ? 'Final payment is charged in IDR through QRIS.'
            : (stablecoin
                ? (stablecoinReady
                    ? `Final payment uses ${String(paymentToken).toUpperCase()}. Send the exact invoice amount.`
                    : 'Choose a coin and network to calculate the final stablecoin amount.')
                : `Catalog prices are shown in ${displayCurrency.toUpperCase()}. Choose a payment method to see the final payment currency.`);
        document.getElementById('checkoutFinalSummary')?.classList.toggle('is-mobile-ready', selectionReady);

        const submit = document.getElementById('checkoutSubmitButton');
        submit.disabled = submitting || !checkoutAvailable ||
            voucherRequestPending ||
            !method ||
            (stablecoin && !stablecoinPricingAvailable) ||
            (stablecoin && !stablecoinReady);
        setButtonLabel(
            submit,
            submitting ? 'Creating Invoice...' : !checkoutAvailable ? 'Checkout Paused' :
            (voucherRequestPending ? 'Checking Voucher...' :
                (!method ? 'Choose Payment Method' :
                    (stablecoin && !stablecoinReady ? 'Complete Payment Selection' : 'Create Invoice')))
        );
    }

    async function refreshVoucher() {
        if (!appliedVoucherCode) return;

        const method = paymentMethod();
        const stablecoin = selectedStablecoin();

        if (!method || ((method === 'crypto' || method === 'binance_pay') && !stablecoin)) {
            throw new Error('Complete the payment selection before applying a voucher.');
        }

        const requestSequence = ++voucherRequestSequence;
        voucherRequestController?.abort();
        voucherRequestController = new AbortController();
        const requestController = voucherRequestController;
        voucherRequestPending = true;
        resetVoucherMotion();
        setButtonLabel(document.getElementById('checkoutApplyVoucher'), 'Checking...');
        voucherFeedback('Checking voucher...', 'loading');
        updateTotals();
        const body = new FormData();
        body.set('code', appliedVoucherCode);
        body.set('payment_method', method);
        if (stablecoin) body.set('coin', stablecoin);

        if (checkoutMode === 'direct') {
            body.set('package_id', String(packageId));
            body.set('quantity', String(quantity));
        }

        try {
            const response = await window.aksaFetchWithCsrf(voucherUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body,
                signal: requestController.signal,
            });
            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(data.message || 'Voucher could not be applied.');
            }

            if (
                requestSequence !== voucherRequestSequence ||
                method !== paymentMethod() ||
                stablecoin !== selectedStablecoin()
            ) {
                return false;
            }

            voucherQuote = data;
            appliedVoucherCode = data.code;
            document.getElementById('checkoutVoucherCode').value = appliedVoucherCode;
            document.getElementById('checkoutVoucherValue').value = appliedVoucherCode;
            voucherFeedback(`${data.discount_percent}% voucher applied to this checkout.`, 'success');
            updateTotals();
            showVoucherAppliedMotion();

            return true;
        } finally {
            if (requestSequence === voucherRequestSequence) {
                voucherRequestController = null;
                voucherRequestPending = false;
                setButtonLabel(
                    document.getElementById('checkoutApplyVoucher'),
                    voucherQuote ? 'Applied' : 'Apply'
                );
                updateTotals();
            }
        }
    }

    form.querySelectorAll('input[name="payment_method"]').forEach(input => {
        input.addEventListener('change', () => {
            abortVoucherRequest();
            form.querySelectorAll('[data-checkout-payment-card]').forEach(card => {
                card.classList.toggle('active', card.contains(input));
            });
            replayCheckoutSelectionMotion(input.closest('[data-checkout-payment-card]'));
            setCheckoutOptionsVisibility(
                document.getElementById('checkoutBinanceOptions'),
                paymentMethod() === 'binance_pay'
            );
            setCheckoutOptionsVisibility(
                document.getElementById('checkoutCryptoOptions'),
                paymentMethod() === 'crypto'
            );
            if (paymentMethod() !== 'crypto') {
                setCheckoutOptionsVisibility(
                    document.getElementById('checkoutCryptoNetworkOptions'),
                    false
                );
            } else {
                refreshCryptoNetworkOptions();
            }
            voucherQuote = null;
            document.getElementById('checkoutVoucherValue').value = '';
            updateTotals();

            if (appliedVoucherCode) {
                refreshVoucher().catch(handleVoucherRefreshError);
            }

            revealFinalSummaryWhenReady();
        });
    });

    form.querySelectorAll('input[name="token"]').forEach(input => {
        input.addEventListener('change', () => {
            abortVoucherRequest();
            document.getElementById('checkoutBinanceOptions')
                ?.querySelectorAll('.crypto-coin-option')
                .forEach(option => option.classList.toggle(
                    'active',
                    option.contains(input) && input.checked
                ));
            voucherQuote = null;
            document.getElementById('checkoutVoucherValue').value = '';
            updateTotals();

            if (appliedVoucherCode) {
                refreshVoucher().catch(handleVoucherRefreshError);
            }

            revealFinalSummaryWhenReady();
        });
    });

    form.querySelectorAll('input[name="crypto_token"]').forEach(input => {
        input.addEventListener('change', () => {
            abortVoucherRequest();
            form.querySelectorAll('[data-checkout-crypto-token-option]').forEach(option => {
                option.classList.toggle('active', option.contains(input) && input.checked);
            });
            replayCheckoutSelectionMotion(input.closest('[data-checkout-crypto-token-option]'));
            form.querySelectorAll('input[name="coin"]').forEach(networkInput => {
                networkInput.checked = false;
                networkInput.closest('[data-checkout-crypto-network-option]')?.classList.remove('active');
            });
            refreshCryptoNetworkOptions();
            voucherQuote = null;
            document.getElementById('checkoutVoucherValue').value = '';
            updateTotals();

            if (appliedVoucherCode) {
                voucherFeedback('Choose a network to recheck this voucher.', 'loading');
            }
        });
    });

    form.querySelectorAll('input[name="coin"]').forEach(input => {
        input.addEventListener('change', () => {
            abortVoucherRequest();
            form.querySelectorAll('[data-checkout-crypto-network-option]').forEach(option => {
                option.classList.toggle('active', option.contains(input) && input.checked);
            });
            replayCheckoutSelectionMotion(input.closest('[data-checkout-crypto-network-option]'));
            voucherQuote = null;
            document.getElementById('checkoutVoucherValue').value = '';
            updateTotals();

            if (appliedVoucherCode) {
                refreshVoucher().catch(handleVoucherRefreshError);
            }

            revealFinalSummaryWhenReady();
        });
    });

    document.getElementById('checkoutOrderReviewToggle')?.addEventListener('click', function() {
        const review = this.closest('.checkout-order-review');
        const expanded = this.getAttribute('aria-expanded') === 'true';
        this.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        review?.classList.toggle('is-open', !expanded);
    });

    document.getElementById('checkoutApplyVoucher')?.addEventListener('click', async function() {
        appliedVoucherCode = document.getElementById('checkoutVoucherCode').value.trim().toUpperCase();

        if (!appliedVoucherCode) {
            voucherFeedback('Enter a voucher code first.', 'error');
            return;
        }

        this.disabled = true;
        setButtonLabel(this, 'Checking...');

        try {
            if (await refreshVoucher()) {
                window.showAppToast?.('Voucher applied', 'Checkout total was updated.', {
                    variant: 'success',
                });
            }
        } catch (error) {
            if (error?.name !== 'AbortError') {
                voucherQuote = null;
                document.getElementById('checkoutVoucherValue').value = '';
                resetVoucherMotion();
                voucherFeedback(error.message, 'error');
                updateTotals();
            }
        } finally {
            this.disabled = false;
            setButtonLabel(this, voucherQuote ? 'Applied' : 'Apply');
            this.classList.toggle('is-voucher-applied', Boolean(voucherQuote));
        }
    });

    document.getElementById('checkoutVoucherCode')?.addEventListener('input', function() {
        this.value = this.value.toUpperCase();

        if (appliedVoucherCode && this.value.trim() !== appliedVoucherCode) {
            clearVoucherFeedback();
            updateTotals();
        }
    });

    form.addEventListener('submit', async event => {
        const method = paymentMethod();

        if (form.dataset.checkoutSubmitting === 'true') {
            event.preventDefault();
            return;
        }

        if (voucherRequestPending) {
            event.preventDefault();
            window.showAppToast?.('Voucher is still checking', 'Wait for the voucher result before creating the invoice.', {
                variant: 'warning',
            });
            return;
        }

        if (!checkoutAvailable || !method) {
            event.preventDefault();
            window.showAppToast?.('Select payment', 'Choose one payment method first.', {
                variant: 'warning',
            });
            return;
        }

        if (method === 'crypto' && !selectedCryptoToken()) {
            event.preventDefault();
            window.showAppToast?.('Select coin', 'Choose USDT or USDC first.', {
                variant: 'warning',
            });
            return;
        }

        if (method === 'crypto' && !selectedCoin()) {
            event.preventDefault();
            window.showAppToast?.('Select network', 'Choose a coin and blockchain network.', {
                variant: 'warning',
            });
            return;
        }

        if (method === 'binance_pay' && !selectedToken()) {
            event.preventDefault();
            window.showAppToast?.('Select coin', 'Choose USDT or USDC for Binance Pay.', {
                variant: 'warning',
            });
            return;
        }

        event.preventDefault();
        form.dataset.checkoutSubmitting = 'true';
        const submit = document.getElementById('checkoutSubmitButton');
        submit.disabled = true;
        setButtonLabel(submit, 'Creating Invoice...');
        const paymentControls = [...form.querySelectorAll('input[name="payment_method"], input[name="coin"], input[name="token"], input[name="crypto_token"], [data-voucher-action], #checkoutVoucherCode')]
            .filter(control => !control.disabled);
        const body = new FormData(form);
        paymentControls.forEach(control => { control.disabled = true; });

        try {
            const response = await window.aksaFetchWithCsrf(form.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body,
            });
            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                if (data.review_url) {
                    window.showAppToast?.('Checkout updated', data.message, { variant: 'warning' });
                    window.setTimeout(() => window.location.assign(data.review_url), 1200);
                    return;
                }
                if (data.redirect_url) {
                    window.showAppToast?.('Existing payment found', data.message || 'Continue your active payment.', {
                        variant: 'warning',
                    });
                    window.setTimeout(() => window.location.assign(data.redirect_url), 650);
                    return;
                }

                throw new Error(data.message || 'The invoice could not be created.');
            }

            const summary = document.getElementById('checkoutFinalSummary');
            await window.animateAksaCheckoutSuccess?.(submit, summary);
            window.location.assign(data.instruction_url || data.redirect_url || '/orders');
        } catch (error) {
            form.dataset.checkoutSubmitting = 'false';
            paymentControls.forEach(control => { control.disabled = false; });
            submit.classList.remove('checkout-submit-success');
            window.showAppToast?.('Checkout not created', error.message || 'Review the checkout and try again.', {
                variant: 'error',
            });
            updateTotals();
        }
    });

    window.addEventListener('aksa:currency-change', updateTotals);
    window.addEventListener('aksa:before-page-swap', () => {
        abortVoucherRequest();
        window.removeEventListener('aksa:currency-change', updateTotals);
    }, {
        once: true,
    });

    setCheckoutOptionsVisibility(
        document.getElementById('checkoutBinanceOptions'),
        paymentMethod() === 'binance_pay'
    );
    setCheckoutOptionsVisibility(
        document.getElementById('checkoutCryptoOptions'),
        paymentMethod() === 'crypto'
    );
    refreshCryptoNetworkOptions();
    updateTotals();
}
