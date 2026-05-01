import './bootstrap';
import QRCode from 'qrcode';

let appToastTimer = null;
let paymentSuccessRedirectTimer = null;
let paymentSuccessCountdownTimer = null;

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
};

const cryptoState = {
    orderId: null,
    pollTimer: null,
};

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function formatIdr(amount) {
    return `Rp ${Number(amount || 0).toLocaleString('id-ID')}`;
}

function formatQrisExpiry(value) {
    if (!value) return '-';

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return date.toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
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

function stopQrisPolling() {
    if (qrisState.pollTimer) {
        clearInterval(qrisState.pollTimer);
        qrisState.pollTimer = null;
    }
}

function startQrisPolling(orderId) {
    stopQrisPolling();

    qrisState.pollTimer = setInterval(async () => {
        try {
            const result = await syncPakasirOrder(orderId);

            if (result?.status === 'paid') {
                stopQrisPolling();
                showPaymentSuccess({
                    message: 'Your QRIS payment has been verified and your license is ready.',
                    licenseKey: result.license_key,
                    orderId: result.order_id || orderId,
                });
            }
        } catch (error) {
            stopQrisPolling();
        }
    }, 5000);
}

window.syncAksaPakasirOrder = syncPakasirOrder;
window.syncAksaCryptoOrder = syncCryptoOrder;

function stopCryptoPolling() {
    if (cryptoState.pollTimer) {
        clearInterval(cryptoState.pollTimer);
        cryptoState.pollTimer = null;
    }
}

function startCryptoPolling(orderId) {
    stopCryptoPolling();

    cryptoState.pollTimer = setInterval(async () => {
        try {
            const result = await syncCryptoOrder(orderId);

            if (result?.status === 'paid') {
                stopCryptoPolling();
                showPaymentSuccess({
                    message: 'Your USDT payment has been verified and your license is ready.',
                    licenseKey: result.license_key,
                    orderId: result.order_id || orderId,
                });
            }
        } catch (error) {
            stopCryptoPolling();
        }
    }, 8000);
}

window.openAksaQrisModal = async function(checkout, options = {}) {
    const modal = document.getElementById('aksaQrisModal');
    const payment = checkout?.pakasir_payment;

    if (!modal || !payment?.payment_number) {
        return false;
    }

    qrisState.orderId = checkout.order_id || null;

    document.getElementById('aksaQrisOrderId').innerText = checkout.order_id || '-';
    document.getElementById('aksaQrisBaseAmount').innerText = formatIdr(payment.amount);
    document.getElementById('aksaQrisFee').innerText = formatIdr(payment.fee);
    document.getElementById('aksaQrisAmount').innerText = formatIdr(payment.total_payment);
    document.getElementById('aksaQrisExpires').innerText = formatQrisExpiry(payment.expired_at);

    const fallback = document.getElementById('aksaQrisFallback');

    if (fallback) {
        fallback.href = checkout.payment_url || '#';
        fallback.classList.toggle('hidden', !checkout.payment_url);
    }

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

    document.getElementById('aksaCryptoOrderId').innerText = checkout.order_id || '-';
    document.getElementById('aksaCryptoNetwork').innerText = payment.network_label || payment.network || '-';
    document.getElementById('aksaCryptoAmount').innerText = formatCryptoAmount(payment.amount, payment.token || 'USDT');
    document.getElementById('aksaCryptoAddress').innerText = payment.address || '-';
    document.getElementById('aksaCryptoContract').innerText = payment.contract || '-';
    document.getElementById('aksaCryptoExpires').innerText = formatQrisExpiry(payment.expired_at);

    const copyAddress = document.getElementById('aksaCryptoCopyAddress');
    const copyAmount = document.getElementById('aksaCryptoCopyAmount');

    if (copyAddress) {
        copyAddress.dataset.copyValue = payment.address || '';
        copyAddress.dataset.copyTitle = 'Address copied';
        copyAddress.dataset.copyMessage = 'Paste the address in your wallet.';
    }

    if (copyAmount) {
        copyAmount.dataset.copyValue = payment.amount || '';
        copyAmount.dataset.copyTitle = 'Amount copied';
        copyAmount.dataset.copyMessage = 'Paste the exact USDT amount in your wallet.';
    }

    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('overflow-hidden');

    if (options.startPolling !== false && cryptoState.orderId) {
        startCryptoPolling(cryptoState.orderId);
    }

    return true;
};

window.closeAksaCryptoModal = function() {
    const modal = document.getElementById('aksaCryptoModal');

    if (!modal) return;

    stopCryptoPolling();
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
        primary.innerText = options.primaryText || 'View License';
    }

    if (copyStatus) {
        copyStatus.innerText = options.licenseKey ? 'Copying license key...' : 'License key is ready on My Licenses.';
    }

    if (countdown) {
        countdown.innerText = `Redirecting to My Licenses in ${Math.ceil(redirectDelay / 1000)}s.`;
    }

    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('overflow-hidden');

    copyLicenseKey(options.licenseKey, copyStatus);
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

async function copyLicenseKey(licenseKey, statusElement) {
    if (!licenseKey) return false;

    if (!navigator.clipboard || !window.isSecureContext) {
        if (statusElement) {
            statusElement.innerText = 'License key is ready on My Licenses.';
        }

        return false;
    }

    try {
        await navigator.clipboard.writeText(licenseKey);

        if (statusElement) {
            statusElement.innerText = 'License key copied automatically.';
        }

        return true;
    } catch (error) {
        if (statusElement) {
            statusElement.innerText = 'License key is ready on My Licenses.';
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

document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-qris-close]')) return;

    window.closeAksaQrisModal?.();
});

document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-crypto-close]')) return;

    window.closeAksaCryptoModal?.();
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
            showPaymentSuccess({
                message: 'Your USDT payment has been verified and your license is ready.',
                licenseKey: result.license_key,
                orderId: result.order_id || cryptoState.orderId,
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
