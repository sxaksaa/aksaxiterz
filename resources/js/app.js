import QRCode from 'qrcode';

let appToastTimer = null;
let paymentSuccessRedirectTimer = null;
let paymentSuccessCountdownTimer = null;
let qrisExpiryCountdownTimer = null;
let cryptoExpiryCountdownTimer = null;
let binancePayExpiryCountdownTimer = null;

window.renderAksaQrCode = async function(target, value, options = {}) {
    const canvas = typeof target === 'string' ? document.querySelector(target) : target;

    if (!canvas || !value) {
        return false;
    }

    await QRCode.toCanvas(canvas, value, {
        errorCorrectionLevel: 'M',
        margin: 1,
        width: options.width || 256,
        color: {
            dark: '#09090c',
            light: '#ffffff',
        },
    });

    return true;
};

const qrisState = {
    orderId: null,
    pollTimer: null,
    isChecking: false,
    expiryHandled: false,
};

const cryptoState = {
    orderId: null,
    pollTimer: null,
    isChecking: false,
    expiryHandled: false,
    token: 'USDT',
};

const binancePayState = {
    orderId: null,
    pollTimer: null,
    isChecking: false,
    expiryHandled: false,
    token: 'USDT',
};

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function setButtonLabel(button, label) {
    const labelTarget = button?.querySelector('[data-button-label]');

    if (labelTarget) {
        labelTarget.textContent = label;
        return;
    }

    if (button) {
        button.innerText = label;
    }
}

function getButtonLabel(button) {
    return button?.querySelector('[data-button-label]')?.textContent || button?.innerText || '';
}

function formatIdr(amount) {
    return `Rp ${Number(amount || 0).toLocaleString('id-ID')}`;
}

function countdownDeadline(value, remainingSeconds) {
    const seconds = Number(remainingSeconds);

    if (Number.isFinite(seconds)) {
        return performance.now() + Math.max(0, seconds) * 1000;
    }

    const expireTime = new Date(value).getTime();

    if (Number.isNaN(expireTime)) return null;

    return performance.now() + Math.max(0, expireTime - Date.now());
}

function formatCountdown(deadline) {
    if (!Number.isFinite(deadline)) return '-';

    const diff = deadline - performance.now();

    if (diff <= 0) return 'Expired';

    const totalSeconds = Math.floor(diff / 1000);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    if (hours > 0) {
        return `${hours}h ${minutes}m ${seconds}s`;
    }

    return `${minutes}m ${seconds}s`;
}

function formatCryptoAmount(amount, token = 'USDT') {
    const numericAmount = Number(amount);

    if (Number.isNaN(numericAmount)) {
        return `${amount || '-'} ${token}`;
    }

    return `${numericAmount.toLocaleString(undefined, {
        minimumFractionDigits: 6,
        maximumFractionDigits: 6,
    })} ${token}`;
}

async function syncPakasirOrder(orderId) {
    if (!orderId) return null;

    const response = await fetch(`/sync-pakasir-order/${encodeURIComponent(orderId)}`, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok && response.status !== 202) {
        const error = new Error(data.error || data.message || `Payment check failed (${response.status})`);
        error.status = response.status;
        throw error;
    }

    return data;
}

async function syncCryptoOrder(orderId) {
    if (!orderId) return null;

    const response = await fetch(`/sync-crypto-order/${encodeURIComponent(orderId)}`, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok && response.status !== 202) {
        const error = new Error(data.error || data.message || `Payment check failed (${response.status})`);
        error.status = response.status;
        throw error;
    }

    return data;
}

async function syncBinancePayOrder(orderId) {
    if (!orderId) return null;

    const response = await fetch(`/sync-binance-pay-order/${encodeURIComponent(orderId)}`, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok && response.status !== 202) {
        const error = new Error(data.error || data.message || `Payment check failed (${response.status})`);
        error.status = response.status;
        throw error;
    }

    return data;
}

function stopQrisPolling() {
    if (qrisState.pollTimer) {
        clearInterval(qrisState.pollTimer);
        qrisState.pollTimer = null;
    }

    qrisState.isChecking = false;
}

function startQrisPolling(orderId) {
    stopQrisPolling();

    qrisState.pollTimer = setInterval(async () => {
        if (qrisState.isChecking || document.hidden) return;

        qrisState.isChecking = true;

        try {
            const result = await syncPakasirOrder(orderId);

            if (result?.status === 'paid') {
                stopQrisPolling();
                showPaymentSuccess({
                    message: 'Your QRIS payment has been verified and your license is ready.',
                    licenseKey: result.license_key,
                    licenseKeys: result.license_keys,
                    orderId: result.order_id || orderId,
                });
            } else if (result?.status && result.status !== 'pending') {
                stopQrisPolling();
            }
        } catch (error) {
            stopQrisPolling();
        } finally {
            qrisState.isChecking = false;
        }
    }, 15000);
}

function stopQrisExpiryCountdown() {
    if (qrisExpiryCountdownTimer) {
        clearInterval(qrisExpiryCountdownTimer);
        qrisExpiryCountdownTimer = null;
    }
}

function startQrisExpiryCountdown(value, remainingSeconds) {
    const element = document.getElementById('aksaQrisExpires');

    if (!element) return;

    stopQrisExpiryCountdown();
    const deadline = countdownDeadline(value, remainingSeconds);

    const update = () => {
        element.innerText = formatCountdown(deadline);
        const expired = element.innerText === 'Expired';
        element.classList.toggle('text-red-300', expired);

        if (expired) {
            handleQrisExpiry();
        }
    };

    update();
    qrisExpiryCountdownTimer = setInterval(update, 1000);
}

async function handleQrisExpiry() {
    if (qrisState.expiryHandled) return;

    qrisState.expiryHandled = true;
    document.getElementById('aksaQrisExpiredOverlay')?.classList.remove('hidden');

    const checkButton = document.getElementById('aksaQrisCheck');

    if (checkButton) {
        setButtonLabel(checkButton, 'Check Final Status');
    }

    if (!qrisState.orderId) return;

    qrisState.isChecking = true;

    try {
        const result = await syncPakasirOrder(qrisState.orderId);

        if (result?.status === 'paid') {
            stopQrisPolling();
            showPaymentSuccess({
                message: 'Your QRIS payment has been verified and your license is ready.',
                licenseKey: result.license_key,
                licenseKeys: result.license_keys,
                orderId: result.order_id || qrisState.orderId,
            });
        } else if (result?.status && result.status !== 'pending') {
            stopQrisPolling();
            window.showAppToast?.('QRIS expired', 'The expired payment was closed. Start a new checkout to pay.', {
                variant: 'warning',
            });
        }
    } catch (error) {
        // Polling will retry while Pakasir confirms the final invoice status.
    } finally {
        qrisState.isChecking = false;
    }
}

window.syncAksaPakasirOrder = syncPakasirOrder;
window.syncAksaCryptoOrder = syncCryptoOrder;
window.syncAksaBinancePayOrder = syncBinancePayOrder;

function stopCryptoPolling() {
    if (cryptoState.pollTimer) {
        clearInterval(cryptoState.pollTimer);
        cryptoState.pollTimer = null;
    }

    cryptoState.isChecking = false;
}

function startCryptoPolling(orderId) {
    stopCryptoPolling();

    cryptoState.pollTimer = setInterval(async () => {
        if (cryptoState.isChecking || document.hidden) return;

        cryptoState.isChecking = true;

        try {
            const result = await syncCryptoOrder(orderId);

            if (result?.status === 'paid') {
                stopCryptoPolling();
                const deliveryPending = result?.delivery_pending === true;

                showPaymentSuccess({
                    message: result.message || 'Your crypto payment has been verified and your license is ready.',
                    licenseKey: result.license_key,
                    licenseKeys: result.license_keys,
                    orderId: result.order_id || orderId,
                    primaryUrl: deliveryPending ? '/orders' : undefined,
                    primaryText: deliveryPending ? 'Open Orders' : undefined,
                    copyStatusText: deliveryPending ? 'Support will deliver this license manually.' : undefined,
                    redirectDelay: deliveryPending ? 8000 : undefined,
                });
            } else if (result?.status && result.status !== 'pending') {
                stopCryptoPolling();
            }
        } catch (error) {
            stopCryptoPolling();
        } finally {
            cryptoState.isChecking = false;
        }
    }, 15000);
}

function stopBinancePayPolling() {
    if (binancePayState.pollTimer) {
        clearInterval(binancePayState.pollTimer);
        binancePayState.pollTimer = null;
    }

    binancePayState.isChecking = false;
}

function startBinancePayPolling(orderId) {
    stopBinancePayPolling();

    binancePayState.pollTimer = setInterval(async () => {
        if (binancePayState.isChecking || document.hidden) return;

        binancePayState.isChecking = true;

        try {
            const result = await syncBinancePayOrder(orderId);

            if (result?.status === 'paid') {
                stopBinancePayPolling();
                const deliveryPending = result?.delivery_pending === true;

                showPaymentSuccess({
                    message: result.message || 'Your Binance Pay transfer has been verified and your license is ready.',
                    licenseKey: result.license_key,
                    licenseKeys: result.license_keys,
                    orderId: result.order_id || orderId,
                    primaryUrl: deliveryPending ? '/orders' : undefined,
                    primaryText: deliveryPending ? 'Open Orders' : undefined,
                    copyStatusText: deliveryPending ? 'Support will deliver this license manually.' : undefined,
                    redirectDelay: deliveryPending ? 8000 : undefined,
                });
            } else if (result?.status && !['pending', 'cancelled'].includes(result.status)) {
                stopBinancePayPolling();
            }
        } catch (error) {
            if (error.status === 410) {
                stopBinancePayPolling();
            }
        } finally {
            binancePayState.isChecking = false;
        }
    }, 15000);
}

function stopCryptoExpiryCountdown() {
    if (cryptoExpiryCountdownTimer) {
        clearInterval(cryptoExpiryCountdownTimer);
        cryptoExpiryCountdownTimer = null;
    }
}

function startCryptoExpiryCountdown(value, remainingSeconds) {
    const element = document.getElementById('aksaCryptoExpires');

    if (!element) return;

    stopCryptoExpiryCountdown();
    const deadline = countdownDeadline(value, remainingSeconds);

    const update = () => {
        element.innerText = formatCountdown(deadline);
        const expired = element.innerText === 'Expired';
        element.classList.toggle('text-red-300', expired);

        if (expired) {
            handleCryptoExpiry();
        }
    };

    update();

    if (!cryptoState.expiryHandled) {
        cryptoExpiryCountdownTimer = setInterval(update, 1000);
    }
}

function resetCryptoExpiryState() {
    cryptoState.expiryHandled = false;
    document.getElementById('aksaCryptoExpiredNotice')?.classList.add('hidden');
    document.getElementById('aksaCryptoPaymentDetails')?.classList.remove('hidden');

    ['aksaCryptoCopyAddress', 'aksaCryptoCopyAmount'].forEach((id) => {
        const button = document.getElementById(id);

        if (!button) return;

        button.disabled = false;
        setButtonLabel(button, 'Copy');
        button.classList.remove('opacity-60', 'pointer-events-none');
    });
}

function handleCryptoExpiry() {
    if (cryptoState.expiryHandled) return;

    cryptoState.expiryHandled = true;
    stopCryptoExpiryCountdown();
    document.getElementById('aksaCryptoExpiredNotice')?.classList.remove('hidden');
    document.getElementById('aksaCryptoPaymentDetails')?.classList.add('hidden');

    const title = document.getElementById('aksaCryptoTitle');

    if (title) {
        title.innerText = `${cryptoState.token} Payment Expired`;
    }

    ['aksaCryptoCopyAddress', 'aksaCryptoCopyAmount'].forEach((id) => {
        const button = document.getElementById(id);

        if (!button) return;

        button.disabled = true;
        button.dataset.copyValue = '';
        button.classList.add('opacity-60', 'pointer-events-none');
    });

    const checkButton = document.getElementById('aksaCryptoCheck');

    if (checkButton) {
        setButtonLabel(checkButton, 'Verify Already Sent');
    }
}

function stopBinancePayExpiryCountdown() {
    if (binancePayExpiryCountdownTimer) {
        clearInterval(binancePayExpiryCountdownTimer);
        binancePayExpiryCountdownTimer = null;
    }
}

function startBinancePayExpiryCountdown(value, remainingSeconds) {
    const element = document.getElementById('aksaBinancePayExpires');

    if (!element) return;

    stopBinancePayExpiryCountdown();
    const deadline = countdownDeadline(value, remainingSeconds);

    const update = () => {
        element.innerText = formatCountdown(deadline);
        const expired = element.innerText === 'Expired';
        element.classList.toggle('text-red-300', expired);

        if (expired && !binancePayState.expiryHandled) {
            binancePayState.expiryHandled = true;
            stopBinancePayExpiryCountdown();
            document.getElementById('aksaBinancePayExpiredNotice')?.classList.remove('hidden');
            document.getElementById('aksaBinancePayDetails')?.classList.add('hidden');
        }
    };

    update();
    binancePayExpiryCountdownTimer = setInterval(update, 1000);
}

window.openAksaQrisModal = async function(checkout, options = {}) {
    const modal = document.getElementById('aksaQrisModal');
    const payment = checkout?.pakasir_payment;

    if (!modal || !payment?.payment_number) {
        return false;
    }

    qrisState.orderId = checkout.order_id || null;
    qrisState.expiryHandled = false;

    document.getElementById('aksaQrisOrderId').innerText = checkout.order_id || '-';
    document.getElementById('aksaQrisBaseAmount').innerText = formatIdr(payment.amount);
    document.getElementById('aksaQrisFee').innerText = formatIdr(payment.fee);
    document.getElementById('aksaQrisAmount').innerText = formatIdr(payment.total_payment);
    document.getElementById('aksaQrisExpiredOverlay')?.classList.add('hidden');
    const checkButton = document.getElementById('aksaQrisCheck');
    if (checkButton) setButtonLabel(checkButton, 'Check Payment');
    startQrisExpiryCountdown(payment.expired_at, payment.remaining_seconds);

    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('overflow-hidden');

    await window.renderAksaQrCode('#aksaQrisCanvas', payment.payment_number, {
        width: 280,
    });

    if (options.startPolling !== false && qrisState.orderId) {
        startQrisPolling(qrisState.orderId);
    }

    return true;
};

window.closeAksaQrisModal = function() {
    const modal = document.getElementById('aksaQrisModal');

    if (!modal) return;

    stopQrisPolling();
    stopQrisExpiryCountdown();
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('overflow-hidden');
};

window.openAksaCryptoModal = async function(checkout, options = {}) {
    const modal = document.getElementById('aksaCryptoModal');
    const payment = checkout?.crypto_payment;

    if (!modal || !payment?.address || !payment?.amount) {
        return false;
    }

    cryptoState.orderId = checkout.order_id || null;
    const token = (payment.token || 'USDT').toUpperCase();
    cryptoState.token = token;
    resetCryptoExpiryState();
    const title = document.getElementById('aksaCryptoTitle');

    if (title) {
        title.innerText = `${token} Payment`;
    }

    document.getElementById('aksaCryptoOrderId').innerText = checkout.order_id || '-';
    document.getElementById('aksaCryptoNetwork').innerText = payment.network_label || payment.network || '-';
    document.getElementById('aksaCryptoAmount').innerText = formatCryptoAmount(payment.amount, token);
    document.getElementById('aksaCryptoAddress').innerText = payment.address || '-';
    document.getElementById('aksaCryptoContract').innerText = payment.contract || '-';

    const copyAddress = document.getElementById('aksaCryptoCopyAddress');
    const copyAmount = document.getElementById('aksaCryptoCopyAmount');
    const checkButton = document.getElementById('aksaCryptoCheck');

    if (copyAddress) {
        copyAddress.dataset.copyValue = payment.address || '';
        copyAddress.dataset.copyTitle = 'Address copied';
        copyAddress.dataset.copyMessage = 'Paste the address in your wallet.';
    }

    if (copyAmount) {
        copyAmount.dataset.copyValue = payment.amount || '';
        copyAmount.dataset.copyTitle = 'Amount copied';
        copyAmount.dataset.copyMessage = `Paste the exact ${token} amount in your wallet.`;
    }

    if (checkButton) {
        checkButton.disabled = false;
        setButtonLabel(checkButton, 'Check Payment');
        checkButton.classList.remove('opacity-60', 'pointer-events-none');
    }

    startCryptoExpiryCountdown(payment.expired_at, payment.remaining_seconds);

    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('overflow-hidden');

    if (options.startPolling === true && cryptoState.orderId) {
        startCryptoPolling(cryptoState.orderId);
    }

    return true;
};

window.closeAksaCryptoModal = function() {
    const modal = document.getElementById('aksaCryptoModal');

    if (!modal) return;

    stopCryptoPolling();
    stopCryptoExpiryCountdown();
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('overflow-hidden');
};

window.openAksaBinancePayModal = async function(checkout, options = {}) {
    const modal = document.getElementById('aksaBinancePayModal');
    const payment = checkout?.binance_pay_payment;

    if (!modal || !payment?.pay_id || !payment?.amount) {
        return false;
    }

    binancePayState.orderId = checkout.order_id || null;
    binancePayState.token = (payment.token || 'USDT').toUpperCase();
    binancePayState.expiryHandled = false;

    document.getElementById('aksaBinancePayExpiredNotice')?.classList.add('hidden');
    document.getElementById('aksaBinancePayDetails')?.classList.remove('hidden');
    document.getElementById('aksaBinancePayOrderId').innerText = checkout.order_id || '-';
    document.getElementById('aksaBinancePayAmount').innerText = formatCryptoAmount(
        payment.amount,
        binancePayState.token
    );
    document.getElementById('aksaBinancePayId').innerText = payment.pay_id || '-';

    const copyId = document.getElementById('aksaBinancePayCopyId');
    const copyAmount = document.getElementById('aksaBinancePayCopyAmount');

    if (copyId) {
        copyId.dataset.copyValue = payment.pay_id || '';
        copyId.dataset.copyTitle = 'Pay ID copied';
        copyId.dataset.copyMessage = 'Paste it in Binance Pay or Send.';
    }

    if (copyAmount) {
        copyAmount.dataset.copyValue = payment.amount || '';
        copyAmount.dataset.copyTitle = 'Amount copied';
        copyAmount.dataset.copyMessage = `Send the exact ${binancePayState.token} amount.`;
    }

    const qrWrap = document.getElementById('aksaBinancePayQrWrap');
    qrWrap?.classList.toggle('hidden', !payment.qr_content);

    if (payment.qr_content) {
        await window.renderAksaQrCode('#aksaBinancePayCanvas', payment.qr_content, {
            width: 280,
        });
    }

    startBinancePayExpiryCountdown(payment.expired_at, payment.remaining_seconds);

    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('overflow-hidden');

    if (options.startPolling !== false && binancePayState.orderId) {
        startBinancePayPolling(binancePayState.orderId);
    }

    return true;
};

window.closeAksaBinancePayModal = function() {
    const modal = document.getElementById('aksaBinancePayModal');

    if (!modal) return;

    stopBinancePayPolling();
    stopBinancePayExpiryCountdown();
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('overflow-hidden');
};

function licenseUrlForOrder(orderId) {
    if (!orderId) {
        return '/licenses';
    }

    const encodedOrderId = encodeURIComponent(orderId);

    return `/licenses?order=${encodedOrderId}#license-${encodedOrderId}`;
}

function showPaymentSuccess(options = {}) {
    const modal = document.getElementById('aksaPaymentSuccessModal');
    const redirectUrl = options.primaryUrl || licenseUrlForOrder(options.orderId);
    const licenseKeys = Array.isArray(options.licenseKeys)
        ? options.licenseKeys.filter((key) => typeof key === 'string' && key !== '')
        : (options.licenseKey ? [options.licenseKey] : []);

    if (!modal) {
        window.showAppToast?.('Payment successful', options.message || 'Your payment has been verified.', {
            variant: 'success',
        });
        setTimeout(() => {
            window.location.href = redirectUrl;
        }, options.redirectDelay || 5000);
        return false;
    }

    window.closeAksaQrisModal?.();
    window.closeAksaCryptoModal?.();
    window.closeAksaBinancePayModal?.();
    clearTimeout(paymentSuccessRedirectTimer);
    clearInterval(paymentSuccessCountdownTimer);

    const message = document.getElementById('aksaPaymentSuccessMessage');
    const primary = document.getElementById('aksaPaymentSuccessPrimary');
    const copyStatus = document.getElementById('aksaPaymentSuccessCopyStatus');
    const countdown = document.getElementById('aksaPaymentSuccessCountdown');
    const redirectDelay = Number(options.redirectDelay || 5000);

    if (message) {
        message.innerText = options.message || 'Your payment has been verified and your license is ready.';
    }

    if (primary) {
        primary.href = redirectUrl;
        primary.innerText = options.primaryText || (licenseKeys.length > 1 ? 'View Licenses' : 'View License');
    }

    if (copyStatus) {
        copyStatus.innerText = options.copyStatusText || (
            licenseKeys.length > 1
                ? `Copying ${licenseKeys.length} license keys...`
                : (licenseKeys.length === 1 ? 'Copying license key...' : 'License keys are ready on My Licenses.')
        );
    }

    if (countdown) {
        countdown.innerText = `Redirecting to My Licenses in ${Math.ceil(redirectDelay / 1000)}s.`;
    }

    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('overflow-hidden');

    copyLicenseKeys(licenseKeys, copyStatus);
    startPaymentSuccessRedirect(redirectUrl, redirectDelay, countdown);

    return true;
}

window.showAksaPaymentSuccess = showPaymentSuccess;

window.closeAksaPaymentSuccessModal = function() {
    const modal = document.getElementById('aksaPaymentSuccessModal');

    if (!modal) return;

    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('overflow-hidden');
};

async function copyLicenseKeys(licenseKeys, statusElement) {
    if (!licenseKeys.length) return false;

    const copiedValue = licenseKeys.join('\n');
    const successMessage = licenseKeys.length > 1
        ? `${licenseKeys.length} license keys copied automatically.`
        : 'License key copied automatically.';

    if (!navigator.clipboard || !window.isSecureContext) {
        if (statusElement) {
            statusElement.innerText = 'License keys are ready on My Licenses.';
        }

        return false;
    }

    try {
        await navigator.clipboard.writeText(copiedValue);

        if (statusElement) {
            statusElement.innerText = successMessage;
        }

        return true;
    } catch (error) {
        if (statusElement) {
            statusElement.innerText = 'License keys are ready on My Licenses.';
        }

        return false;
    }
}

function startPaymentSuccessRedirect(url, delay, countdownElement) {
    let remaining = Math.max(1, Math.ceil(delay / 1000));

    clearTimeout(paymentSuccessRedirectTimer);
    clearInterval(paymentSuccessCountdownTimer);

    if (countdownElement) {
        countdownElement.innerText = `Redirecting to My Licenses in ${remaining}s.`;
    }

    paymentSuccessCountdownTimer = setInterval(() => {
        remaining -= 1;

        if (countdownElement) {
            countdownElement.innerText = `Redirecting to My Licenses in ${Math.max(0, remaining)}s.`;
        }

        if (remaining <= 0) {
            clearInterval(paymentSuccessCountdownTimer);
        }
    }, 1000);

    paymentSuccessRedirectTimer = setTimeout(() => {
        window.location.href = url;
    }, delay);
}

window.showAppToast = function(title, message = '', options = {}) {
    const toast = document.getElementById('appToast');
    const toastTitle = document.getElementById('appToastTitle');
    const toastMessage = document.getElementById('appToastMessage');

    if (!toast || !toastTitle || !toastMessage) return;

    const variant = options.variant || 'info';
    const duration = Number(options.duration || 3400);

    toast.dataset.variant = variant;
    toastTitle.innerText = title;
    toastMessage.innerText = message;
    toast.classList.add('is-visible');

    clearTimeout(appToastTimer);

    if (options.redirectAfter) {
        appToastTimer = setTimeout(() => {
            window.location.href = options.redirectAfter;
        }, options.redirectDelay || 900);
        return;
    }

    appToastTimer = setTimeout(() => {
        toast.classList.remove('is-visible');
    }, duration);
};

const customSelects = new WeakMap();

function customSelectOptionLabel(option) {
    return (option?.textContent || option?.label || option?.value || 'Select').trim();
}

function selectedCustomSelectOption(select) {
    return select.selectedOptions?.[0] || select.options?.[select.selectedIndex] || select.querySelector('option');
}

function customSelectVisibleOptions(select) {
    return Array.from(select.options || []).filter((option) => !option.hidden);
}

function closeCustomSelect(select) {
    const state = customSelects.get(select);

    if (!state) return;

    state.wrapper.classList.remove('is-open');
    state.panel.classList.add('hidden');
    state.trigger.setAttribute('aria-expanded', 'false');

    if (state.section) {
        state.section.style.zIndex = state.previousSectionZIndex || '';
        state.section.dataset.aksaSelectOpen = 'false';
    }
}

function closeOtherCustomSelects(currentSelect = null) {
    document.querySelectorAll('select[data-aksa-select-enhanced]').forEach((select) => {
        if (select !== currentSelect) {
            closeCustomSelect(select);
        }
    });
}

function refreshCustomSelect(select) {
    const state = customSelects.get(select);

    if (!state) return;

    const selectedOption = selectedCustomSelectOption(select);

    state.label.textContent = customSelectOptionLabel(selectedOption);
    state.trigger.disabled = select.disabled;
    state.panel.innerHTML = '';

    const visibleOptions = customSelectVisibleOptions(select);

    if (visibleOptions.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'aksa-select-option is-disabled';
        empty.textContent = 'No options available';
        state.panel.appendChild(empty);
        return;
    }

    visibleOptions.forEach((option) => {
        const button = document.createElement('button');
        const check = document.createElement('span');
        const isSelected = option.value === select.value;

        button.type = 'button';
        button.className = 'aksa-select-option';
        button.disabled = option.disabled;
        button.dataset.value = option.value;
        button.setAttribute('role', 'option');
        button.setAttribute('aria-selected', String(isSelected));

        if (isSelected) {
            button.classList.add('is-active');
        }

        if (option.disabled) {
            button.classList.add('is-disabled');
        }

        button.append(document.createTextNode(customSelectOptionLabel(option)));
        check.className = 'aksa-select-option-check';
        button.appendChild(check);
        button.addEventListener('click', () => {
            if (option.disabled) return;

            select.value = option.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            refreshCustomSelect(select);
            closeCustomSelect(select);
        });
        state.panel.appendChild(button);
    });
}

function enhanceCustomSelect(select) {
    if (select.dataset.aksaSelectEnhanced || select.multiple) return;

    const wrapper = document.createElement('div');
    const trigger = document.createElement('button');
    const label = document.createElement('span');
    const chevron = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    const chevronPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    const panel = document.createElement('div');
    const section = select.closest('.product-section');

    select.dataset.aksaSelectEnhanced = 'true';
    select.tabIndex = -1;

    wrapper.className = 'aksa-select';
    trigger.type = 'button';
    trigger.className = 'aksa-select-trigger';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    label.className = 'aksa-select-label';
    chevron.classList.add('aksa-select-chevron');
    chevron.setAttribute('viewBox', '0 0 24 24');
    chevron.setAttribute('fill', 'none');
    chevron.setAttribute('stroke', 'currentColor');
    chevron.setAttribute('stroke-width', '1.8');
    chevron.setAttribute('aria-hidden', 'true');
    chevronPath.setAttribute('stroke-linecap', 'round');
    chevronPath.setAttribute('stroke-linejoin', 'round');
    chevronPath.setAttribute('d', 'm6 9 6 6 6-6');
    chevron.appendChild(chevronPath);
    panel.className = 'aksa-select-panel hidden';
    panel.setAttribute('role', 'listbox');
    trigger.append(label, chevron);
    wrapper.append(trigger, panel);
    select.insertAdjacentElement('afterend', wrapper);

    customSelects.set(select, {
        wrapper,
        trigger,
        label,
        panel,
        section,
        previousSectionZIndex: section?.style.zIndex || '',
    });

    trigger.addEventListener('click', (event) => {
        event.stopPropagation();

        if (select.disabled) return;

        const state = customSelects.get(select);
        const isOpen = state.wrapper.classList.contains('is-open');

        closeOtherCustomSelects(select);
        state.wrapper.classList.toggle('is-open', !isOpen);
        state.panel.classList.toggle('hidden', isOpen);
        state.trigger.setAttribute('aria-expanded', String(!isOpen));

        if (state.section) {
            state.section.style.position = 'relative';
            state.section.style.zIndex = isOpen ? state.previousSectionZIndex : '80';
            state.section.dataset.aksaSelectOpen = String(!isOpen);
        }
    });

    select.addEventListener('change', () => refreshCustomSelect(select));
    select.addEventListener('aksa-select-refresh', () => refreshCustomSelect(select));

    const observer = new MutationObserver(() => refreshCustomSelect(select));
    observer.observe(select, {
        attributes: true,
        childList: true,
        subtree: true,
        characterData: true,
    });

    refreshCustomSelect(select);
}

function initializeCustomSelects(root = document) {
    root.querySelectorAll('select.search-bar:not([data-native-select])').forEach(enhanceCustomSelect);
}

window.refreshAksaCustomSelects = function() {
    document.querySelectorAll('select[data-aksa-select-enhanced]').forEach((select) => refreshCustomSelect(select));
};

window.initializeAksaPageEnhancements = function(root = document) {
    initializeCustomSelects(root);
};

let mobileMenuOpen = false;
let lastNavbarScroll = window.pageYOffset || 0;
let activeSoftNavigation = null;
let activePageScriptCleanup = null;

function navButton() {
    return document.getElementById('menuBtn');
}

function mobileMenu() {
    return document.getElementById('mobileMenu');
}

function setNavButtonLabel(button, label) {
    setButtonLabel(button, label);
}

function openMobileMenu() {
    const menu = mobileMenu();
    const button = navButton();

    if (!menu || !button) return;

    mobileMenuOpen = true;
    menu.classList.remove('opacity-0', '-translate-y-5', 'pointer-events-none');
    menu.classList.add('opacity-100', 'translate-y-0');
    setNavButtonLabel(button, 'Close');
}

function closeMobileMenu() {
    const menu = mobileMenu();
    const button = navButton();

    if (!menu || !button) return;

    mobileMenuOpen = false;
    menu.classList.add('opacity-0', '-translate-y-5', 'pointer-events-none');
    menu.classList.remove('opacity-100', 'translate-y-0');
    setNavButtonLabel(button, 'Menu');
}

function toggleProfileDropdown() {
    document.getElementById('dropdown')?.classList.toggle('hidden');
}

function closeProfileDropdown() {
    document.getElementById('dropdown')?.classList.add('hidden');
}

function updateNavbarOnScroll() {
    const navbar = document.getElementById('navbar');

    if (!navbar) return;

    const currentScroll = window.pageYOffset;
    navbar.classList.toggle('nav-hidden', currentScroll > lastNavbarScroll && currentScroll > 50);
    lastNavbarScroll = currentScroll;
}

function pageContentShell() {
    return document.querySelector('[data-aksa-page-content]');
}

function samePageHashNavigation(url) {
    return url.origin === window.location.origin
        && url.pathname === window.location.pathname
        && url.search === window.location.search
        && url.hash
        && url.hash !== window.location.hash;
}

function shouldSoftNavigateLink(link, event) {
    if (!link || event.defaultPrevented || event.button !== 0) return false;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return false;
    if (link.dataset.noSoftNav !== undefined || link.closest('[data-no-soft-nav]')) return false;
    if (link.hasAttribute('download')) return false;

    const target = (link.getAttribute('target') || '').toLowerCase();

    if (target && target !== '_self') return false;

    const rawHref = link.getAttribute('href');

    if (!rawHref || rawHref === '#' || rawHref.startsWith('mailto:') || rawHref.startsWith('tel:')) return false;

    const url = new URL(rawHref, window.location.href);

    if (url.origin !== window.location.origin) return false;
    if (samePageHashNavigation(url)) return false;
    if (url.href === window.location.href) return false;

    return ![
        '/auth/',
        '/logout',
        '/process-order/',
        '/pay-crypto/',
        '/pay-binance/',
        '/sync-',
        '/cancel-order/',
        '/pakasir-callback',
    ].some((blockedPath) => url.pathname.startsWith(blockedPath));
}

function shouldSoftNavigateForm(form, event) {
    if (!form || event.defaultPrevented) return false;
    if (form.dataset.noSoftNav !== undefined || form.closest('[data-no-soft-nav]')) return false;

    const method = (form.getAttribute('method') || 'GET').toUpperCase();

    return method === 'GET';
}

function formNavigationUrl(form, submitter = null) {
    const url = new URL(form.getAttribute('action') || window.location.href, window.location.href);
    const formData = new FormData(form);

    if (submitter?.name && !formData.has(submitter.name)) {
        formData.append(submitter.name, submitter.value || '');
    }

    url.search = '';
    formData.forEach((value, key) => {
        if (value instanceof File || value === '') return;
        url.searchParams.append(key, value);
    });

    return url.href;
}

function withCleanupSignal(options, signal) {
    if (options === undefined) return { signal };
    if (typeof options === 'boolean') return { capture: options, signal };

    return {
        ...options,
        signal,
    };
}

function createTrackedPageRuntime() {
    const controller = new AbortController();
    const intervals = new Set();
    const timeouts = new Set();

    const trackedSetInterval = (handler, timeout, ...args) => {
        const id = window.setInterval(handler, timeout, ...args);
        intervals.add(id);

        return id;
    };

    const trackedClearInterval = (id) => {
        intervals.delete(id);
        window.clearInterval(id);
    };

    const trackedSetTimeout = (handler, timeout, ...args) => {
        const id = window.setTimeout(() => {
            timeouts.delete(id);
            handler(...args);
        }, timeout);
        timeouts.add(id);

        return id;
    };

    const trackedClearTimeout = (id) => {
        timeouts.delete(id);
        window.clearTimeout(id);
    };

    const trackedDocument = new Proxy(document, {
        get(target, property) {
            if (property === 'addEventListener') {
                return (type, listener, options) => {
                    if (type === 'DOMContentLoaded' && target.readyState !== 'loading') {
                        trackedSetTimeout(() => listener.call(target, new Event('DOMContentLoaded')), 0);
                        return undefined;
                    }

                    return target.addEventListener(type, listener, withCleanupSignal(options, controller.signal));
                };
            }

            const value = target[property];

            return typeof value === 'function' ? value.bind(target) : value;
        },
        set(target, property, value) {
            target[property] = value;
            return true;
        },
    });

    const trackedWindow = new Proxy(window, {
        get(target, property) {
            if (property === 'addEventListener') {
                return (type, listener, options) => target.addEventListener(
                    type,
                    listener,
                    withCleanupSignal(options, controller.signal),
                );
            }

            if (property === 'setInterval') return trackedSetInterval;
            if (property === 'clearInterval') return trackedClearInterval;
            if (property === 'setTimeout') return trackedSetTimeout;
            if (property === 'clearTimeout') return trackedClearTimeout;

            const value = target[property];

            return typeof value === 'function' ? value.bind(target) : value;
        },
        set(target, property, value) {
            target[property] = value;
            return true;
        },
    });

    return {
        document: trackedDocument,
        window: trackedWindow,
        setInterval: trackedSetInterval,
        clearInterval: trackedClearInterval,
        setTimeout: trackedSetTimeout,
        clearTimeout: trackedClearTimeout,
        cleanup() {
            controller.abort();
            intervals.forEach((id) => window.clearInterval(id));
            timeouts.forEach((id) => window.clearTimeout(id));
            intervals.clear();
            timeouts.clear();
        },
    };
}

function cleanupSoftPageScripts() {
    activePageScriptCleanup?.();
    activePageScriptCleanup = null;
}

function runnableScript(script) {
    if (script.src) return false;

    const type = (script.getAttribute('type') || 'text/javascript').toLowerCase();

    return ['text/javascript', 'application/javascript', 'module'].includes(type);
}

function executeSoftPageScripts(nextDocument) {
    cleanupSoftPageScripts();

    const scripts = [...nextDocument.body.querySelectorAll('script')].filter(runnableScript);

    if (scripts.length === 0) return;

    const runtime = createTrackedPageRuntime();
    activePageScriptCleanup = runtime.cleanup;

    scripts.forEach((script) => {
        const code = script.textContent?.trim();

        if (!code) return;

        const runner = new Function(
            'window',
            'document',
            'setInterval',
            'clearInterval',
            'setTimeout',
            'clearTimeout',
            code,
        );

        runner(
            runtime.window,
            runtime.document,
            runtime.setInterval,
            runtime.clearInterval,
            runtime.setTimeout,
            runtime.clearTimeout,
        );
    });
}

function replaceOptionalShell(selector, nextDocument) {
    const current = document.querySelector(selector);
    const next = nextDocument.querySelector(selector);

    if (current && next) {
        current.replaceWith(next);
    }
}

function updateDocumentMeta(nextDocument) {
    document.title = nextDocument.title || document.title;

    const currentCsrf = document.querySelector('meta[name="csrf-token"]');
    const nextCsrf = nextDocument.querySelector('meta[name="csrf-token"]');

    if (currentCsrf && nextCsrf) {
        currentCsrf.setAttribute('content', nextCsrf.getAttribute('content') || '');
    }
}

function scrollAfterSoftNavigation(url) {
    if (url.hash) {
        document.getElementById(decodeURIComponent(url.hash.slice(1)))?.scrollIntoView({ block: 'start' });
        return;
    }

    window.scrollTo({ top: 0, behavior: 'auto' });
}

function dashboardChartPointFromEvent(event) {
    const target = event.target;

    return target instanceof Element ? target.closest('[data-chart-point]') : null;
}

function setDashboardChartTooltipText(tooltip, selector, value) {
    const element = tooltip.querySelector(selector);

    if (element) {
        element.textContent = value || '-';
    }
}

function dashboardChartPointPosition(point, frame) {
    const svg = point.ownerSVGElement;
    const matrix = svg?.getScreenCTM?.();
    const x = Number(point.dataset.x);
    const y = Number(point.dataset.y);

    if (svg && matrix && Number.isFinite(x) && Number.isFinite(y)) {
        const svgPoint = svg.createSVGPoint();
        svgPoint.x = x;
        svgPoint.y = y;

        const screenPoint = svgPoint.matrixTransform(matrix);
        const frameRect = frame.getBoundingClientRect();

        return {
            left: screenPoint.x - frameRect.left,
            top: screenPoint.y - frameRect.top,
        };
    }

    const pointRect = point.getBoundingClientRect();
    const frameRect = frame.getBoundingClientRect();

    return {
        left: pointRect.left + (pointRect.width / 2) - frameRect.left,
        top: pointRect.top + (pointRect.height / 2) - frameRect.top,
    };
}

function showDashboardChartTooltip(point) {
    const frame = point.closest('[data-dashboard-chart]');
    const tooltip = frame?.querySelector('[data-dashboard-chart-tooltip]');

    if (!frame || !tooltip) return;

    setDashboardChartTooltipText(tooltip, '[data-chart-tooltip-title]', point.dataset.label || point.dataset.shortLabel);
    setDashboardChartTooltipText(tooltip, '[data-chart-tooltip-orders]', point.dataset.orders);
    setDashboardChartTooltipText(tooltip, '[data-chart-tooltip-idr]', point.dataset.idr);
    setDashboardChartTooltipText(tooltip, '[data-chart-tooltip-crypto]', point.dataset.crypto);

    tooltip.classList.remove('is-below');
    tooltip.setAttribute('aria-hidden', 'false');

    const frameRect = frame.getBoundingClientRect();
    const position = dashboardChartPointPosition(point, frame);
    const tooltipWidth = tooltip.offsetWidth || 192;
    const tooltipHeight = tooltip.offsetHeight || 110;
    const minLeft = Math.min((tooltipWidth / 2) + 12, Math.max(12, frameRect.width / 2));
    const maxLeft = Math.max(minLeft, frameRect.width - (tooltipWidth / 2) - 12);
    const left = Math.min(maxLeft, Math.max(minLeft, position.left));
    const top = Math.max(16, position.top);

    tooltip.style.left = `${left}px`;
    tooltip.style.top = `${top}px`;
    tooltip.classList.toggle('is-below', top < tooltipHeight + 18);
    tooltip.classList.add('is-visible');

    if (tooltip.id) {
        point.setAttribute('aria-describedby', tooltip.id);
    }
}

function hideDashboardChartTooltip(point = null) {
    const frame = point?.closest('[data-dashboard-chart]') || document.querySelector('[data-dashboard-chart]');
    const tooltip = frame?.querySelector('[data-dashboard-chart-tooltip]');

    if (!tooltip) return;

    tooltip.classList.remove('is-visible');
    tooltip.setAttribute('aria-hidden', 'true');
    point?.removeAttribute('aria-describedby');
}

async function softNavigate(url, options = {}) {
    const nextUrl = new URL(url, window.location.href);
    const currentContent = pageContentShell();

    if (!currentContent) {
        window.location.href = nextUrl.href;
        return;
    }

    activeSoftNavigation?.abort();

    const controller = new AbortController();
    activeSoftNavigation = controller;
    const pendingLink = options.pendingLink || null;

    window.dispatchEvent(new CustomEvent('aksa:before-page-swap'));
    document.dispatchEvent(new CustomEvent('aksa:before-page-swap'));
    currentContent.classList.remove('aksa-soft-nav-entered');
    document.body.classList.add('aksa-soft-nav-active');
    currentContent.setAttribute('aria-busy', 'true');
    pendingLink?.classList.add('is-soft-nav-pending');

    try {
        const response = await fetch(nextUrl.href, {
            credentials: 'same-origin',
            headers: {
                Accept: 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
            signal: controller.signal,
        });

        const contentType = response.headers.get('content-type') || '';

        if (!response.ok || !contentType.includes('text/html')) {
            throw new Error(`Soft navigation failed (${response.status})`);
        }

        if (response.redirected && new URL(response.url).origin !== window.location.origin) {
            window.location.href = nextUrl.href;
            return;
        }

        const html = await response.text();
        const nextDocument = new DOMParser().parseFromString(html, 'text/html');
        const nextContent = nextDocument.querySelector('[data-aksa-page-content]');

        if (!nextContent) {
            window.location.href = nextUrl.href;
            return;
        }

        replaceOptionalShell('[data-aksa-nav-shell]', nextDocument);
        replaceOptionalShell('[data-aksa-footer-shell]', nextDocument);
        currentContent.replaceWith(nextContent);
        updateDocumentMeta(nextDocument);

        if (options.pushHistory !== false) {
            window.history.pushState({ aksaSoftNavigation: true }, '', nextUrl.href);
        }

        executeSoftPageScripts(nextDocument);
        initializeCustomSelects(nextContent);
        closeMobileMenu();
        closeProfileDropdown();
        scrollAfterSoftNavigation(nextUrl);

        requestAnimationFrame(() => {
            nextContent.classList.add('aksa-soft-nav-entered');
            window.setTimeout(() => nextContent.classList.remove('aksa-soft-nav-entered'), 260);
        });
    } catch (error) {
        if (error.name === 'AbortError') return;

        window.location.href = nextUrl.href;
    } finally {
        if (activeSoftNavigation === controller) {
            activeSoftNavigation = null;
            document.body.classList.remove('aksa-soft-nav-active');
            pageContentShell()?.removeAttribute('aria-busy');
        }

        pendingLink?.classList.remove('is-soft-nav-pending');
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initializeCustomSelects());
} else {
    initializeCustomSelects();
}

document.addEventListener('click', (event) => {
    const mobileToggle = event.target.closest('[data-mobile-menu-toggle]');

    if (mobileToggle) {
        event.stopPropagation();
        mobileMenuOpen ? closeMobileMenu() : openMobileMenu();
        return;
    }

    if (event.target.closest('[data-mobile-menu-link]')) {
        closeMobileMenu();
    }

    const profileToggle = event.target.closest('[data-profile-toggle]');

    if (profileToggle) {
        event.stopPropagation();
        toggleProfileDropdown();
        return;
    }

    const menu = mobileMenu();
    const button = navButton();

    if (menu && button && !menu.contains(event.target) && !button.contains(event.target)) {
        closeMobileMenu();
    }

    if (!event.target.closest('#dropdown') && !event.target.closest('[data-profile-toggle]')) {
        closeProfileDropdown();
    }
});

window.addEventListener('scroll', updateNavbarOnScroll, { passive: true });

document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]');

    if (!shouldSoftNavigateLink(link, event)) return;

    event.preventDefault();
    softNavigate(new URL(link.getAttribute('href'), window.location.href).href, {
        pendingLink: link,
    });
});

document.addEventListener('submit', (event) => {
    const form = event.target.closest('form');

    if (!shouldSoftNavigateForm(form, event)) return;

    event.preventDefault();
    softNavigate(formNavigationUrl(form, event.submitter));
});

window.addEventListener('popstate', () => {
    softNavigate(window.location.href, {
        pushHistory: false,
    });
});

document.addEventListener('pointerover', (event) => {
    const point = dashboardChartPointFromEvent(event);

    if (point) {
        showDashboardChartTooltip(point);
    }
});

document.addEventListener('pointermove', (event) => {
    const point = dashboardChartPointFromEvent(event);

    if (point) {
        showDashboardChartTooltip(point);
    }
});

document.addEventListener('pointerout', (event) => {
    const point = dashboardChartPointFromEvent(event);

    if (!point) return;

    const nextPoint = event.relatedTarget instanceof Element
        ? event.relatedTarget.closest('[data-chart-point]')
        : null;

    if (nextPoint !== point) {
        hideDashboardChartTooltip(point);
    }
});

document.addEventListener('focusin', (event) => {
    const point = dashboardChartPointFromEvent(event);

    if (point) {
        showDashboardChartTooltip(point);
    }
});

document.addEventListener('focusout', (event) => {
    const point = dashboardChartPointFromEvent(event);

    if (point) {
        hideDashboardChartTooltip(point);
    }
});

document.addEventListener('click', (event) => {
    const point = dashboardChartPointFromEvent(event);

    if (point) {
        showDashboardChartTooltip(point);
        return;
    }

    if (!event.target.closest('[data-dashboard-chart]')) {
        hideDashboardChartTooltip();
    }
});

document.addEventListener('aksa:before-page-swap', () => hideDashboardChartTooltip());

document.addEventListener('click', (event) => {
    if (!event.target.closest('.aksa-select')) {
        closeOtherCustomSelects();
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closeOtherCustomSelects();
        hideDashboardChartTooltip();
    }
});

document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-confirm]');

    if (!form) return;

    const message = form.dataset.confirm || 'Continue this action?';

    if (!window.confirm(message)) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
}, true);

document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-qris-close]')) return;

    window.closeAksaQrisModal?.();
});

document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-crypto-close]')) return;

    window.closeAksaCryptoModal?.();
});

document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-binance-pay-close]')) return;

    window.closeAksaBinancePayModal?.();
});

document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-payment-success-close]')) return;

    window.closeAksaPaymentSuccessModal?.();
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-qris-check]');

    if (!button || !qrisState.orderId) return;

    const originalText = getButtonLabel(button);

    button.disabled = true;
    setButtonLabel(button, 'Checking...');
    button.classList.add('opacity-60', 'pointer-events-none');

    try {
        const result = await syncPakasirOrder(qrisState.orderId);

        if (result?.status === 'paid') {
            stopQrisPolling();
            showPaymentSuccess({
                message: 'Your QRIS payment has been verified and your license is ready.',
                licenseKey: result.license_key,
                licenseKeys: result.license_keys,
                orderId: result.order_id || qrisState.orderId,
            });
            return;
        }

        window.showAppToast?.('Still pending', result?.message || 'Payment is still being verified.', {
            variant: 'warning',
        });
    } catch (error) {
        window.showAppToast?.('Payment check failed', error.message || 'Please try again in a moment.', {
            variant: 'error',
        });
    } finally {
        button.disabled = false;
        setButtonLabel(button, originalText || 'Check Payment');
        button.classList.remove('opacity-60', 'pointer-events-none');
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-crypto-check]');

    if (!button || !cryptoState.orderId) return;

    const originalText = getButtonLabel(button);

    button.disabled = true;
    setButtonLabel(button, 'Checking...');
    button.classList.add('opacity-60', 'pointer-events-none');

    try {
        const result = await syncCryptoOrder(cryptoState.orderId);

        if (result?.status === 'paid') {
            stopCryptoPolling();
            const deliveryPending = result?.delivery_pending === true;

            showPaymentSuccess({
                message: result.message || 'Your crypto payment has been verified and your license is ready.',
                licenseKey: result.license_key,
                licenseKeys: result.license_keys,
                orderId: result.order_id || cryptoState.orderId,
                primaryUrl: deliveryPending ? '/orders' : undefined,
                primaryText: deliveryPending ? 'Open Orders' : undefined,
                copyStatusText: deliveryPending ? 'Support will deliver this license manually.' : undefined,
                redirectDelay: deliveryPending ? 8000 : undefined,
            });
            return;
        }

        window.showAppToast?.('Still pending', result?.message || 'Payment is still being verified.', {
            variant: 'warning',
        });
    } catch (error) {
        window.showAppToast?.('Payment check failed', error.message || 'Please try again in a moment.', {
            variant: 'error',
        });
    } finally {
        button.disabled = false;
        setButtonLabel(button, originalText || 'Check Payment');
        button.classList.remove('opacity-60', 'pointer-events-none');
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-binance-pay-check]');

    if (!button || !binancePayState.orderId) return;

    const originalText = getButtonLabel(button);
    button.disabled = true;
    setButtonLabel(button, 'Checking...');
    button.classList.add('opacity-60', 'pointer-events-none');

    try {
        const result = await syncBinancePayOrder(binancePayState.orderId);

        if (result?.status === 'paid') {
            stopBinancePayPolling();
            const deliveryPending = result?.delivery_pending === true;

            showPaymentSuccess({
                message: result.message || 'Your Binance Pay transfer has been verified and your license is ready.',
                licenseKey: result.license_key,
                licenseKeys: result.license_keys,
                orderId: result.order_id || binancePayState.orderId,
                primaryUrl: deliveryPending ? '/orders' : undefined,
                primaryText: deliveryPending ? 'Open Orders' : undefined,
                copyStatusText: deliveryPending ? 'Support will deliver this license manually.' : undefined,
                redirectDelay: deliveryPending ? 8000 : undefined,
            });
            return;
        }

        window.showAppToast?.('Still pending', result?.message || 'Payment is still being verified.', {
            variant: 'warning',
        });
    } catch (error) {
        window.showAppToast?.('Payment check failed', error.message || 'Please try again in a moment.', {
            variant: 'error',
        });
    } finally {
        button.disabled = false;
        setButtonLabel(button, originalText || 'Check Payment');
        button.classList.remove('opacity-60', 'pointer-events-none');
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy-license]');

    if (!button) return;

    const key = document.getElementById(`key-${button.dataset.copyLicense}`);
    const text = key?.innerText?.trim();

    if (!text) return;

    const originalText = getButtonLabel(button);

    try {
        await navigator.clipboard.writeText(text);
        setButtonLabel(button, 'Copied!');
        button.classList.add('text-green-400');
        window.showAppToast?.('License copied', 'The license key is ready to paste.', {
            variant: 'success',
        });
    } catch (error) {
        window.showAppToast?.('Copy failed', 'Select the license key and copy it manually.', {
            variant: 'error',
        });
    } finally {
        setTimeout(() => {
            setButtonLabel(button, originalText || 'Copy');
            button.classList.remove('text-green-400');
        }, 1200);
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy-value]');

    if (!button) return;

    const text = button.dataset.copyValue?.trim();

    if (!text) return;

    const originalText = getButtonLabel(button);

    try {
        await navigator.clipboard.writeText(text);
        setButtonLabel(button, 'Copied!');
        button.classList.add('text-green-400');
        window.showAppToast?.(
            button.dataset.copyTitle || 'Copied',
            button.dataset.copyMessage || 'The text is ready to paste.', {
                variant: 'success',
            }
        );
    } catch (error) {
        window.showAppToast?.('Copy failed', 'Select the text and copy it manually.', {
            variant: 'error',
        });
    } finally {
        setTimeout(() => {
            setButtonLabel(button, originalText || 'Copy');
            button.classList.remove('text-green-400');
        }, 1200);
    }
});
