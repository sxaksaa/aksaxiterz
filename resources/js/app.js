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
        checkButton.innerText = 'Check Final Status';
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
        button.innerText = 'Copy';
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
        checkButton.innerText = 'Verify Already Sent';
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
    if (checkButton) checkButton.innerText = 'Check Payment';
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
        checkButton.innerText = 'Check Payment';
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

    const originalText = button.innerText;

    button.disabled = true;
    button.innerText = 'Checking...';
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
        button.innerText = originalText || 'Check Payment';
        button.classList.remove('opacity-60', 'pointer-events-none');
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-crypto-check]');

    if (!button || !cryptoState.orderId) return;

    const originalText = button.innerText;

    button.disabled = true;
    button.innerText = 'Checking...';
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
        button.innerText = originalText || 'Check Payment';
        button.classList.remove('opacity-60', 'pointer-events-none');
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-binance-pay-check]');

    if (!button || !binancePayState.orderId) return;

    const originalText = button.innerText;
    button.disabled = true;
    button.innerText = 'Checking...';
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
        button.innerText = originalText || 'Check Payment';
        button.classList.remove('opacity-60', 'pointer-events-none');
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy-license]');

    if (!button) return;

    const key = document.getElementById(`key-${button.dataset.copyLicense}`);
    const text = key?.innerText?.trim();

    if (!text) return;

    const originalText = button.innerText;

    try {
        await navigator.clipboard.writeText(text);
        button.innerText = 'Copied!';
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
            button.innerText = originalText || 'Copy';
            button.classList.remove('text-green-400');
        }, 1200);
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy-value]');

    if (!button) return;

    const text = button.dataset.copyValue?.trim();

    if (!text) return;

    const originalText = button.innerText;

    try {
        await navigator.clipboard.writeText(text);
        button.innerText = 'Copied!';
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
            button.innerText = originalText || 'Copy';
            button.classList.remove('text-green-400');
        }, 1200);
    }
});
