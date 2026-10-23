import { setButtonLabel, getButtonLabel, formatIdr } from './ui.js';
import { shouldReduceMotion } from './motion-preferences.js';

let paymentSuccessRedirectTimer = null;

let paymentSuccessCountdownTimer = null;

const modalReturnFocus = new WeakMap();

const modalCloseTimers = new WeakMap();

const MODAL_TRANSITION_DURATION = 380;

function modalFocusableElements(modal) {
    return [...modal.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )].filter((element) => (
        !element.hidden &&
        element.getAttribute('aria-hidden') !== 'true' &&
        element.getClientRects().length > 0
    ));
}

function openAccessibleModal(modal) {
    if (!(modal instanceof HTMLElement)) return;

    if (document.activeElement instanceof HTMLElement && !modal.contains(document.activeElement)) {
        modalReturnFocus.set(modal, document.activeElement);
    }

    window.clearTimeout(modalCloseTimers.get(modal));
    modalCloseTimers.delete(modal);
    modal.classList.remove('hidden', 'is-closing');
    modal.removeAttribute('inert');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('overflow-hidden');

    window.requestAnimationFrame(() => {
        modal.classList.add('is-open');

        window.requestAnimationFrame(() => {
            modalFocusableElements(modal)[0]?.focus();
        });
    });
}

function closeAccessibleModal(modal) {
    if (!(modal instanceof HTMLElement) || modal.classList.contains('hidden') || modal.classList.contains('is-closing')) return;

    modal.classList.remove('is-open');
    modal.classList.add('is-closing');
    modal.setAttribute('inert', '');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('overflow-hidden');

    const returnTarget = modalReturnFocus.get(modal);
    modalReturnFocus.delete(modal);
    if (returnTarget?.isConnected) returnTarget.focus();

    const finishClosing = () => {
        modal.classList.add('hidden');
        modal.classList.remove('is-closing');
        modalCloseTimers.delete(modal);
    };

    if (shouldReduceMotion()) {
        finishClosing();
        return;
    }

    modalCloseTimers.set(modal, window.setTimeout(finishClosing, MODAL_TRANSITION_DURATION));
}

function activePaymentModal() {
    return document.querySelector('.qris-modal:not(.hidden)[aria-hidden="false"]');
}

function closePaymentModal(modal) {
    const closeById = {
        aksaQrisModal: window.closeAksaQrisModal,
        aksaCryptoModal: window.closeAksaCryptoModal,
        aksaBinancePayModal: window.closeAksaBinancePayModal,
        aksaPaymentSuccessModal: window.closeAksaPaymentSuccessModal,
    };

    closeById[modal?.id]?.();
}

let qrisExpiryCountdownTimer = null;

let cryptoExpiryCountdownTimer = null;

let binancePayExpiryCountdownTimer = null;

function loadQrLogo(source) {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = reject;
        image.src = source;
    });
}

const qrisState = {
    orderId: null,
    statusUrl: null,
    pollTimer: null,
    pollNow: null,
    pollGeneration: 0,
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
        minimumFractionDigits: 2,
        maximumFractionDigits: 6,
    })} ${token}`;
}

async function syncGopayQrisOrder(orderId, statusUrl = null) {
    if (!orderId) return null;

    const endpoint = statusUrl || `/sync-gopay-qris-order/${encodeURIComponent(orderId)}`;
    const response = await window.aksaFetchWithCsrf(endpoint, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
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

async function syncQrisOrder(orderId) {
    return syncGopayQrisOrder(orderId, qrisState.statusUrl);
}

async function syncCryptoOrder(orderId) {
    if (!orderId) return null;

    const response = await window.aksaFetchWithCsrf(`/sync-crypto-order/${encodeURIComponent(orderId)}`, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
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

    const response = await window.aksaFetchWithCsrf(`/sync-binance-pay-order/${encodeURIComponent(orderId)}`, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
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
    qrisState.pollGeneration += 1;

    if (qrisState.pollTimer) {
        clearInterval(qrisState.pollTimer);
        qrisState.pollTimer = null;
    }

    qrisState.pollNow = null;
    qrisState.isChecking = false;
}

function setQrisAutoStatus(state, title, message) {
    const status = document.getElementById('aksaQrisAutoStatus');
    const titleElement = document.getElementById('aksaQrisAutoStatusTitle');
    const messageElement = document.getElementById('aksaQrisAutoStatusMessage');

    if (!status) return;

    if (status.dataset.state !== state) status.dataset.state = state;
    if (titleElement && titleElement.textContent !== title) titleElement.textContent = title;
    if (messageElement && messageElement.textContent !== message) messageElement.textContent = message;
}

function showQrisPaymentSuccess(result, fallbackOrderId) {
    const deliveryPending = result?.delivery_pending === true;

    showPaymentSuccess({
        message: result?.message || (
            deliveryPending
                ? 'Payment verified. Your license delivery is being prepared.'
                : 'Your QRIS payment has been verified and your license is ready.'
        ),
        licenseKey: result?.license_key,
        licenseKeys: result?.license_keys,
        orderId: result?.order_id || fallbackOrderId,
        primaryUrl: deliveryPending ? '/orders' : undefined,
        primaryText: deliveryPending ? 'Open Orders' : undefined,
        copyStatusText: deliveryPending ? 'Payment is safe. License delivery is still pending.' : undefined,
        redirectDelay: deliveryPending ? 8000 : undefined,
    });
}

function startQrisPolling(orderId) {
    stopQrisPolling();
    const generation = qrisState.pollGeneration;
    const isCurrentOrder = () => (
        qrisState.pollGeneration === generation &&
        qrisState.orderId === orderId
    );

    const poll = async () => {
        if (!isCurrentOrder() || qrisState.isChecking || document.hidden) return;

        qrisState.isChecking = true;

        try {
            const result = await syncQrisOrder(orderId);

            if (!isCurrentOrder()) return;

            if (result?.status === 'paid') {
                stopQrisPolling();
                setQrisAutoStatus('paid', 'Payment verified', result.message || 'Your payment has been secured.');
                showQrisPaymentSuccess(result, orderId);
            } else if (result?.status && result.status !== 'pending') {
                stopQrisPolling();
                setQrisAutoStatus('closed', 'Checkout closed', result.message || 'This payment window is no longer active.');
            } else {
                setQrisAutoStatus('waiting', 'Waiting for payment', 'Automatic verification will check again in 15 seconds.');
            }
        } catch (error) {
            if (isCurrentOrder()) {
                setQrisAutoStatus('retrying', 'Connection interrupted', 'No action needed. We will retry automatically when the connection returns.');
            }
        } finally {
            if (isCurrentOrder()) {
                qrisState.isChecking = false;
            }
        }
    };

    qrisState.pollNow = poll;
    qrisState.pollTimer = setInterval(poll, 15000);
    void poll();
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

    if (!qrisState.orderId || qrisState.isChecking) return;

    const checkingOrderId = qrisState.orderId;
    const checkingGeneration = qrisState.pollGeneration;
    qrisState.isChecking = true;

    try {
        const result = await syncQrisOrder(checkingOrderId);

        if (
            qrisState.orderId !== checkingOrderId ||
            qrisState.pollGeneration !== checkingGeneration
        ) {
            return;
        }

        if (result?.status === 'paid') {
            stopQrisPolling();
            setQrisAutoStatus('paid', 'Payment verified', result.message || 'Your payment has been secured.');
            showQrisPaymentSuccess(result, checkingOrderId);
        } else if (result?.status && result.status !== 'pending') {
            stopQrisPolling();
            setQrisAutoStatus('closed', 'Checkout closed', result.message || 'This payment window is no longer active.');
            window.showAppToast?.('QRIS expired', result.message || 'The expired payment was closed. Start a new checkout to pay.', {
                variant: 'warning',
            });
        }
    } catch (error) {
        if (
            qrisState.orderId === checkingOrderId &&
            qrisState.pollGeneration === checkingGeneration
        ) {
            setQrisAutoStatus('retrying', 'Connection interrupted', 'We will retry automatically when the connection returns.');
        }
    } finally {
        if (
            qrisState.orderId === checkingOrderId &&
            qrisState.pollGeneration === checkingGeneration
        ) {
            qrisState.isChecking = false;
        }
    }
}

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
    const redirectLabel = options.redirectLabel || (
        redirectUrl.startsWith('/orders') ? 'Orders' : 'My Licenses'
    );
    const licenseKeys = Array.isArray(options.licenseKeys)
        ? options.licenseKeys.filter((key) => typeof key === 'string' && key !== '')
        : (options.licenseKey ? [options.licenseKey] : []);

    if (!modal) {
        launchPaymentCelebration(document.body);
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
        countdown.innerText = `Redirecting to ${redirectLabel} in ${Math.ceil(redirectDelay / 1000)}s.`;
    }

    const successMark = modal.querySelector('.payment-success-mark');
    const successDialog = modal.querySelector('.payment-success-dialog');
    if (successMark) {
        successMark.classList.remove('is-animating');
        void successMark.offsetWidth;
        successMark.classList.add('is-animating');
    }

    if (successDialog) {
        successDialog.classList.remove('is-celebrating');
        void successDialog.offsetWidth;
        successDialog.classList.add('is-celebrating');
    }

    openAccessibleModal(modal);
    launchPaymentCelebration(modal);

    copyLicenseKeys(licenseKeys, copyStatus);
    startPaymentSuccessRedirect(redirectUrl, redirectDelay, countdown, redirectLabel);

    return true;
}

function launchPaymentCelebration(container) {
    if (!(container instanceof Element) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    container.querySelector('[data-payment-confetti]')?.remove();
    const burst = document.createElement('div');
    burst.dataset.paymentConfetti = '';
    burst.className = 'payment-confetti';

    for (let index = 0; index < 14; index += 1) {
        const piece = document.createElement('span');
        const angle = (360 / 14) * index;
        const distance = 72 + (index % 4) * 12;
        piece.style.setProperty('--confetti-x', `${Math.cos(angle * Math.PI / 180) * distance}px`);
        piece.style.setProperty('--confetti-y', `${Math.sin(angle * Math.PI / 180) * distance}px`);
        piece.style.setProperty('--confetti-delay', `${(index % 3) * 24}ms`);
        piece.style.setProperty('--confetti-rotate', `${120 + index * 27}deg`);
        burst.appendChild(piece);
    }

    container.appendChild(burst);
    window.setTimeout(() => burst.remove(), 1050);
}

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

function startPaymentSuccessRedirect(url, delay, countdownElement, redirectLabel = 'My Licenses') {
    let remaining = Math.max(1, Math.ceil(delay / 1000));

    clearTimeout(paymentSuccessRedirectTimer);
    clearInterval(paymentSuccessCountdownTimer);

    if (countdownElement) {
        countdownElement.innerText = `Redirecting to ${redirectLabel} in ${remaining}s.`;
    }

    paymentSuccessCountdownTimer = setInterval(() => {
        remaining -= 1;

        if (countdownElement) {
            countdownElement.innerText = `Redirecting to ${redirectLabel} in ${Math.max(0, remaining)}s.`;
        }

        if (remaining <= 0) {
            clearInterval(paymentSuccessCountdownTimer);
        }
    }, 1000);

    paymentSuccessRedirectTimer = setTimeout(() => {
        window.location.href = url;
    }, delay);
}

export { modalFocusableElements, activePaymentModal, closePaymentModal, qrisState, syncQrisOrder, stopQrisPolling, setQrisAutoStatus, showQrisPaymentSuccess };

export function registerPayments() {
    window.renderAksaQrCode = async function(target, value, options = {}) {
        const canvas = typeof target === 'string' ? document.querySelector(target) : target;

        if (!canvas || !value) {
            return false;
        }

        const { default: QRCode } = await import('qrcode');
        await QRCode.toCanvas(canvas, value, {
            errorCorrectionLevel: 'H',
            margin: 1,
            width: options.width || 256,
            color: {
                dark: options.darkColor || '#09090c',
                light: options.lightColor || '#ffffff',
            },
        });

        if (options.logoUrl) {
            const logo = await loadQrLogo(options.logoUrl);
            const context = canvas.getContext('2d');
            const logoSize = Math.round(canvas.width * 0.18);
            const logoPadding = Math.round(logoSize * 0.14);
            const backgroundSize = logoSize + (logoPadding * 2);
            const backgroundX = Math.round((canvas.width - backgroundSize) / 2);
            const backgroundY = Math.round((canvas.height - backgroundSize) / 2);
            const logoX = backgroundX + logoPadding;
            const logoY = backgroundY + logoPadding;
            const backgroundRadius = backgroundSize / 2;
            const backgroundCenterX = canvas.width / 2;
            const backgroundCenterY = canvas.height / 2;

            context.save();
            context.beginPath();
            context.arc(backgroundCenterX, backgroundCenterY, backgroundRadius, 0, Math.PI * 2);
            context.fillStyle = options.logoBackground || '#18112b';
            context.fill();
            context.lineWidth = Math.max(2, Math.round(canvas.width * 0.008));
            context.strokeStyle = options.logoBorder || '#7c3aed';
            context.stroke();

            context.imageSmoothingEnabled = true;
            context.imageSmoothingQuality = 'high';
            context.drawImage(logo, logoX, logoY, logoSize, logoSize);
            context.restore();
        }

        return true;
    };

    window.renderAksaStyledQrCode = async function(target, value, options = {}) {
        const container = typeof target === 'string' ? document.querySelector(target) : target;

        if (!container || !value) {
            return false;
        }

        const size = options.width || 280;
        const foreground = options.darkColor || '#171120';
        const background = options.lightColor || '#eee7ff';

        const { default: QRCodeStyling } = await import('qr-code-styling');
        container.replaceChildren();

        const qrCode = new QRCodeStyling({
            width: size,
            height: size,
            type: 'canvas',
            data: value,
            margin: 20,
            qrOptions: {
                errorCorrectionLevel: 'H',
            },
            dotsOptions: {
                color: foreground,
                type: 'rounded',
                roundSize: false,
            },
            cornersSquareOptions: {
                color: foreground,
                type: 'dot',
            },
            cornersDotOptions: {
                color: foreground,
                type: 'dot',
            },
            backgroundOptions: {
                color: background,
            },
        });

        qrCode.append(container);

        if (options.logoUrl) {
            const logoBadge = document.createElement('span');
            const logo = document.createElement('img');

            logoBadge.className = 'qris-logo-badge';
            logo.src = options.logoUrl;
            logo.alt = '';
            logo.decoding = 'async';
            logoBadge.append(logo);
            container.append(logoBadge);
        }

        return true;
    };

    window.syncAksaGopayQrisOrder = syncGopayQrisOrder;

    window.syncAksaCryptoOrder = syncCryptoOrder;

    window.syncAksaBinancePayOrder = syncBinancePayOrder;

    window.openAksaQrisModal = async function(checkout, options = {}) {
        const modal = document.getElementById('aksaQrisModal');
        const payment = checkout?.qris_payment;
        const qrPayload = payment?.qr_payload || payment?.payment_number;

        if (!modal || !qrPayload) {
            return false;
        }

        stopQrisPolling();
        qrisState.orderId = checkout.order_id || null;
        qrisState.statusUrl = checkout.method === 'gopay_qris'
            ? (checkout.status_url || `/sync-gopay-qris-order/${encodeURIComponent(qrisState.orderId || '')}`)
            : null;
        qrisState.expiryHandled = false;

        document.getElementById('aksaQrisOrderId').innerText = checkout.order_id || '-';
        document.getElementById('aksaQrisBaseAmount').innerText = formatIdr(payment.base_amount ?? payment.amount);
        document.getElementById('aksaQrisPlatformFee').innerText = formatIdr(payment.platform_fee ?? payment.fee ?? 0);
        document.getElementById('aksaQrisUniqueAmount').innerText = formatIdr(payment.unique_amount ?? 0);
        const totalPayment = payment.total_payment ?? payment.amount;
        document.getElementById('aksaQrisAmount').innerText = formatIdr(totalPayment);
        const copyAmount = document.getElementById('aksaQrisCopyAmount');
        if (copyAmount) {
            copyAmount.dataset.copyValue = totalPayment || '';
        }
        document.getElementById('aksaQrisExpiredOverlay')?.classList.add('hidden');
        const checkButton = document.getElementById('aksaQrisCheck');
        if (checkButton) setButtonLabel(checkButton, 'Check Now');
        setQrisAutoStatus('waiting', 'Automatic verification active', 'We check this payment securely every 15 seconds.');

        openAccessibleModal(modal);

        await window.renderAksaStyledQrCode('#aksaQrisCanvas', qrPayload, {
            width: 320,
            logoUrl: '/images/brand/aksa-xiterz-mark.png',
            darkColor: '#171120',
            lightColor: '#eee7ff',
        });

        if (options.startPolling !== false && qrisState.orderId) {
            startQrisPolling(qrisState.orderId);
        }

        startQrisExpiryCountdown(payment.expired_at, payment.remaining_seconds);

        return true;
    };

    window.closeAksaQrisModal = function() {
        const modal = document.getElementById('aksaQrisModal');

        if (!modal) return;

        stopQrisPolling();
        stopQrisExpiryCountdown();
        qrisState.orderId = null;
        qrisState.statusUrl = null;
        setQrisAutoStatus('waiting', 'Automatic verification active', 'We check this payment securely every 15 seconds.');
        closeAccessibleModal(modal);
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

        openAccessibleModal(modal);

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
        closeAccessibleModal(modal);
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

        openAccessibleModal(modal);

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
        closeAccessibleModal(modal);
    };

    window.showAksaPaymentSuccess = showPaymentSuccess;

    window.closeAksaPaymentSuccessModal = function() {
        const modal = document.getElementById('aksaPaymentSuccessModal');

        if (!modal) return;

        clearTimeout(paymentSuccessRedirectTimer);
        clearInterval(paymentSuccessCountdownTimer);
        closeAccessibleModal(modal);
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
        if (!event.target.closest('[data-binance-pay-close]')) return;

        window.closeAksaBinancePayModal?.();
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('[data-payment-success-close]')) return;

        window.closeAksaPaymentSuccessModal?.();
    });

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            void qrisState.pollNow?.();
        }
    });

    window.addEventListener('online', () => {
        void qrisState.pollNow?.();
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
        const button = event.target.closest('[data-qris-check]');

        if (!button || !qrisState.orderId) return;

        if (qrisState.isChecking) {
            window.showAppToast?.('Already checking', 'Automatic verification is already in progress.');
            return;
        }

        const originalText = getButtonLabel(button);
        const checkingOrderId = qrisState.orderId;
        const checkingGeneration = qrisState.pollGeneration;

        qrisState.isChecking = true;
        button.disabled = true;
        setButtonLabel(button, 'Checking...');
        button.classList.add('opacity-60', 'pointer-events-none');
        setQrisAutoStatus('checking', 'Checking payment', 'Reading the latest secure payment status.');

        try {
            const result = await syncQrisOrder(checkingOrderId);

            if (
                qrisState.orderId !== checkingOrderId ||
                qrisState.pollGeneration !== checkingGeneration
            ) {
                return;
            }

            if (result?.status === 'paid') {
                stopQrisPolling();
                setQrisAutoStatus('paid', 'Payment verified', result.message || 'Your payment has been secured.');
                showQrisPaymentSuccess(result, checkingOrderId);
                return;
            }

            if (result?.status && result.status !== 'pending') {
                stopQrisPolling();
                setQrisAutoStatus('closed', 'Checkout closed', result.message || 'This payment window is no longer active.');
                window.showAppToast?.('Checkout closed', result.message || 'Start a new checkout when you are ready to pay.', {
                    variant: 'warning',
                });
            } else {
                setQrisAutoStatus('waiting', 'Waiting for payment', 'Automatic verification will check again in 15 seconds.');
                window.showAppToast?.('Still waiting', result?.message || 'Payment is still being verified automatically.', {
                    variant: 'warning',
                });
            }
        } catch (error) {
            if (
                qrisState.orderId === checkingOrderId &&
                qrisState.pollGeneration === checkingGeneration
            ) {
                setQrisAutoStatus('retrying', 'Connection interrupted', 'No action needed. We will retry automatically when the connection returns.');
                window.showAppToast?.('Payment check failed', error.message || 'Please try again in a moment.', {
                    variant: 'error',
                });
            }
        } finally {
            if (
                qrisState.orderId === checkingOrderId &&
                qrisState.pollGeneration === checkingGeneration
            ) {
                qrisState.isChecking = false;
            }
            button.disabled = false;
            setButtonLabel(button, originalText || 'Check Now');
            button.classList.remove('opacity-60', 'pointer-events-none');
        }
    });

}
