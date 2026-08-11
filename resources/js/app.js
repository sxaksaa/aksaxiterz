import QRCode from 'qrcode';
import QRCodeStyling from 'qr-code-styling';

let appToastTimer = null;
let paymentSuccessRedirectTimer = null;
let paymentSuccessCountdownTimer = null;
const modalReturnFocus = new WeakMap();

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

    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('overflow-hidden');

    window.requestAnimationFrame(() => {
        modalFocusableElements(modal)[0]?.focus();
    });
}

function closeAccessibleModal(modal) {
    if (!(modal instanceof HTMLElement) || modal.classList.contains('hidden')) return;

    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('overflow-hidden');

    const returnTarget = modalReturnFocus.get(modal);
    modalReturnFocus.delete(modal);
    if (returnTarget?.isConnected) returnTarget.focus();
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
let recentPurchaseToastCleanup = null;
const MINI_CART_SHEET_BREAKPOINT = 768;

function usesMiniCartSheet() {
    return window.innerWidth < MINI_CART_SHEET_BREAKPOINT;
}

function shouldLockPageForMiniCart() {
    const hasPreciseHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;

    return usesMiniCartSheet() && !hasPreciseHover;
}

function loadQrLogo(source) {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = reject;
        image.src = source;
    });
}

window.renderAksaQrCode = async function(target, value, options = {}) {
    const canvas = typeof target === 'string' ? document.querySelector(target) : target;

    if (!canvas || !value) {
        return false;
    }

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

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function setCsrfToken(token) {
    if (!token) return '';

    let meta = document.querySelector('meta[name="csrf-token"]');

    if (!meta) {
        meta = document.createElement('meta');
        meta.setAttribute('name', 'csrf-token');
        document.head.appendChild(meta);
    }

    meta.setAttribute('content', token);
    document.querySelectorAll('input[name="_token"]').forEach((input) => {
        input.value = token;
    });

    return token;
}

async function refreshCsrfToken() {
    const response = await fetch('/csrf-token', {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        throw new Error('Your secure session expired. Refresh the page and try again.');
    }

    const data = await response.json().catch(() => ({}));
    const token = data.token || '';

    if (!token) {
        throw new Error('Your secure session expired. Refresh the page and try again.');
    }

    return setCsrfToken(token);
}

window.aksaCsrfToken = csrfToken;
window.setAksaCsrfToken = setCsrfToken;
window.refreshAksaCsrfToken = refreshCsrfToken;

window.aksaFetchWithCsrf = async function(url, options = {}) {
    const requestOptions = {
        ...options,
        credentials: options.credentials || 'same-origin',
    };
    const requestHeaders = new Headers(requestOptions.headers || {});

    requestHeaders.set('X-CSRF-TOKEN', csrfToken());
    requestOptions.headers = requestHeaders;

    let response = await fetch(url, requestOptions);

    if (response.status !== 419) {
        return response;
    }

    await refreshCsrfToken();
    const retryHeaders = new Headers(options.headers || {});

    retryHeaders.set('X-CSRF-TOKEN', csrfToken());

    return fetch(url, {
        ...options,
        credentials: options.credentials || 'same-origin',
        headers: retryHeaders,
    });
};

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

const DISPLAY_CURRENCY_STORAGE_KEY = 'aksa_display_currency';
const DISPLAY_CURRENCIES = new Set(['idr', 'usd']);
const INDONESIAN_TIMEZONES = new Set([
    'Asia/Jakarta',
    'Asia/Pontianak',
    'Asia/Makassar',
    'Asia/Ujung_Pandang',
    'Asia/Jayapura',
]);
let activeDisplayCurrency = null;

function normalizedDisplayCurrency(currency) {
    const normalized = String(currency || '').trim().toLowerCase();

    return DISPLAY_CURRENCIES.has(normalized) ? normalized : null;
}

function storedDisplayCurrency() {
    try {
        return normalizedDisplayCurrency(window.localStorage.getItem(DISPLAY_CURRENCY_STORAGE_KEY));
    } catch (error) {
        return null;
    }
}

function detectedDisplayCurrency() {
    const savedCurrency = storedDisplayCurrency();

    if (savedCurrency) return savedCurrency;

    const visitorCountry = String(document.documentElement.dataset.visitorCountry || '').toUpperCase();

    if (/^[A-Z]{2}$/.test(visitorCountry)) {
        return visitorCountry === 'ID' ? 'idr' : 'usd';
    }

    const browserLanguages = Array.isArray(navigator.languages) && navigator.languages.length > 0
        ? navigator.languages
        : [navigator.language];
    const usesIndonesian = /^id(?:-|$)/i.test(String(browserLanguages[0] || ''));

    if (usesIndonesian) return 'idr';

    const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

    return INDONESIAN_TIMEZONES.has(browserTimezone) ? 'idr' : 'usd';
}

function formatDisplayUsd(amount) {
    const numericAmount = Number(amount || 0);

    return `$${numericAmount.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 4,
    })}`;
}

function formatDisplayPrice(idrAmount, usdAmount, currency = activeDisplayCurrency) {
    return normalizedDisplayCurrency(currency) === 'usd'
        ? formatDisplayUsd(usdAmount)
        : formatIdr(idrAmount);
}

function refreshDisplayCurrency(root = document) {
    const currency = activeDisplayCurrency || detectedDisplayCurrency();
    const scope = root?.querySelectorAll ? root : document;

    scope.querySelectorAll('[data-display-price]').forEach(element => {
        const idrAmount = element.dataset.priceIdr;
        const usdAmount = element.dataset.priceUsd;

        if (idrAmount === undefined) return;

        const prefix = element.dataset.pricePrefix || '';
        const suffix = element.dataset.priceSuffix || '';

        const nextText = currency === 'usd' && (usdAmount === undefined || usdAmount === '')
            ? (element.dataset.priceUsdFallback || 'USD unavailable')
            : `${prefix}${formatDisplayPrice(idrAmount, usdAmount, currency)}${suffix}`;

        if (element.textContent !== nextText && document.documentElement.dataset.currencyReady === 'true') {
            element.classList.remove('aksa-price-changing');
            void element.offsetWidth;
            element.classList.add('aksa-price-changing');
            window.setTimeout(() => element.classList.remove('aksa-price-changing'), 300);
        }

        element.textContent = nextText;
    });

    scope.querySelectorAll('[data-currency-text]').forEach(element => {
        const value = currency === 'usd'
            ? element.dataset.currencyTextUsd
            : element.dataset.currencyTextIdr;

        if (value !== undefined) element.textContent = value;
    });

    scope.querySelectorAll('[data-currency-visibility]').forEach(element => {
        const visible = currency === 'usd'
            ? element.dataset.currencyVisibleUsd === 'true'
            : element.dataset.currencyVisibleIdr === 'true';

        element.classList.toggle('hidden', !visible);
    });

    document.querySelectorAll('[data-currency-option]').forEach(button => {
        const selected = button.dataset.currencyOption === currency;

        button.setAttribute('aria-pressed', selected ? 'true' : 'false');
        button.classList.toggle('text-white', selected);
        button.classList.toggle('text-gray-400', !selected);
    });

    document.querySelectorAll('[data-currency-switcher]').forEach(switcher => {
        switcher.dataset.selectedCurrency = currency;
    });

    document.documentElement.dataset.displayCurrency = currency;
    document.documentElement.dataset.currencyReady = 'true';
}

function notifyDisplayCurrencyChanged(source = 'automatic') {
    window.dispatchEvent(new CustomEvent('aksa:currency-change', {
        detail: {
            currency: activeDisplayCurrency,
            source,
        },
    }));
}

function initializeDisplayCurrency(root = document) {
    activeDisplayCurrency = activeDisplayCurrency || detectedDisplayCurrency();
    refreshDisplayCurrency(root);
    notifyDisplayCurrencyChanged('automatic');
}

window.getAksaDisplayCurrency = () => activeDisplayCurrency || detectedDisplayCurrency();
window.formatAksaDisplayPrice = (idrAmount, usdAmount, currency = null) => formatDisplayPrice(
    idrAmount,
    usdAmount,
    currency || window.getAksaDisplayCurrency(),
);
window.refreshAksaDisplayCurrency = (root = document) => refreshDisplayCurrency(root);
window.setAksaDisplayCurrency = (currency, options = {}) => {
    const normalized = normalizedDisplayCurrency(currency);

    if (!normalized) return false;

    activeDisplayCurrency = normalized;

    if (options.persist !== false) {
        try {
            window.localStorage.setItem(DISPLAY_CURRENCY_STORAGE_KEY, normalized);
        } catch (error) {
            // The selection still applies to this page when storage is unavailable.
        }
    }

    refreshDisplayCurrency(document);
    notifyDisplayCurrencyChanged(options.source || 'manual');

    return true;
};
window.addEventListener('storage', event => {
    if (event.key !== DISPLAY_CURRENCY_STORAGE_KEY) return;

    const currency = normalizedDisplayCurrency(event.newValue);

    if (currency) {
        window.setAksaDisplayCurrency(currency, {
            persist: false,
            source: 'storage',
        });
    }
});

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

window.syncAksaGopayQrisOrder = syncGopayQrisOrder;
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
    if (successMark) {
        successMark.classList.remove('is-animating');
        void successMark.offsetWidth;
        successMark.classList.add('is-animating');
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

window.showAksaPaymentSuccess = showPaymentSuccess;

window.closeAksaPaymentSuccessModal = function() {
    const modal = document.getElementById('aksaPaymentSuccessModal');

    if (!modal) return;

    clearTimeout(paymentSuccessRedirectTimer);
    clearInterval(paymentSuccessCountdownTimer);
    closeAccessibleModal(modal);
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

window.showAppToast = function(title, message = '', options = {}) {
    const toast = document.getElementById('appToast');
    const toastTitle = document.getElementById('appToastTitle');
    const toastMessage = document.getElementById('appToastMessage');

    if (!toast || !toastTitle || !toastMessage) return;

    const variant = options.variant || 'info';
    const duration = Number(options.duration || 3400);
    const redirectDelay = Number(options.redirectDelay || 900);
    const visibleDuration = options.redirectAfter ? redirectDelay : duration;

    toast.dataset.variant = variant;
    toastTitle.innerText = title;
    toastMessage.innerText = message;
    toast.style.setProperty('--app-toast-duration', `${visibleDuration}ms`);
    toast.classList.remove('is-visible');
    void toast.offsetWidth;
    window.requestAnimationFrame(() => toast.classList.add('is-visible'));

    clearTimeout(appToastTimer);

    if (options.redirectAfter) {
        appToastTimer = setTimeout(() => {
            window.location.href = options.redirectAfter;
        }, redirectDelay);
        return;
    }

    appToastTimer = setTimeout(() => {
        toast.classList.remove('is-visible');
    }, duration);
};

const RECENT_PURCHASE_SNOOZE_KEY = 'aksa_recent_purchase_snoozed_until';
const RECENT_PURCHASE_VISIBLE_MS = window.matchMedia('(max-width: 639px)').matches ? 5200 : 8200;
const RECENT_PURCHASE_GAP_MS = 14500;
const RECENT_PURCHASE_INITIAL_DELAY_MS = 1800;
const RECENT_PURCHASE_NEW_DELAY_MS = 350;
const RECENT_PURCHASE_POLL_MS = 20000;
const RECENT_PURCHASE_MAX_KNOWN_KEYS = 100;

function clearRecentPurchaseToast() {
    recentPurchaseToastCleanup?.();
    recentPurchaseToastCleanup = null;
}

function recentPurchaseSnoozed() {
    try {
        return Number(window.localStorage.getItem(RECENT_PURCHASE_SNOOZE_KEY) || 0) > Date.now();
    } catch (error) {
        return false;
    }
}

function snoozeRecentPurchaseToast() {
    try {
        window.localStorage.setItem(
            RECENT_PURCHASE_SNOOZE_KEY,
            String(Date.now() + (6 * 60 * 60 * 1000)),
        );
    } catch (error) {
        // localStorage can be unavailable in private browsing; hiding still works for this page.
    }
}

function parseRecentPurchaseData(toast) {
    const template = toast?.querySelector('[data-recent-purchase-data]');
    const raw = template?.innerHTML?.trim() || '[]';

    try {
        const purchases = JSON.parse(raw);

        return Array.isArray(purchases)
            ? normalizeRecentPurchases(purchases)
            : [];
    } catch (error) {
        return [];
    }
}

function recentPurchaseKey(purchase) {
    const key = String(purchase?.key || '').trim();

    if (key) return key;

    return [
        purchase?.paid_at || '',
        purchase?.buyer || '',
        purchase?.product || '',
        purchase?.package || '',
        Number(purchase?.quantity || 1),
    ].join('|');
}

function normalizeRecentPurchases(purchases) {
    if (!Array.isArray(purchases)) return [];

    const uniquePurchases = new Map();

    purchases.forEach((purchase) => {
        if (!purchase || !purchase.product) return;

        const key = recentPurchaseKey(purchase);

        if (!key || uniquePurchases.has(key)) return;

        uniquePurchases.set(key, {
            ...purchase,
            key,
        });
    });

    return Array.from(uniquePurchases.values());
}

function recentPurchaseFeedUrl(toast) {
    const endpoint = toast?.dataset.recentPurchaseEndpoint?.trim();

    if (!endpoint) return null;

    const url = new URL(endpoint, window.location.origin);
    const productSlug = toast.dataset.recentPurchaseProductSlug?.trim();

    if (productSlug) {
        url.searchParams.set('product', productSlug);
    }

    return url;
}

function recentPurchaseRelativeTime(paidAt, fallback = 'recently') {
    const timestamp = Date.parse(paidAt || '');

    if (!Number.isFinite(timestamp)) return fallback || 'recently';

    const seconds = Math.max(0, Math.floor((Date.now() - timestamp) / 1000));

    if (seconds < 60) return 'just now';

    const minutes = Math.floor(seconds / 60);

    if (minutes < 60) return `${minutes}m ago`;

    const hours = Math.floor(minutes / 60);

    if (hours < 24) return `${hours}h ago`;

    const days = Math.floor(hours / 24);

    if (days < 14) return `${days}d ago`;

    return new Intl.DateTimeFormat('en-GB', {
        day: '2-digit',
        month: 'short',
    }).format(new Date(timestamp));
}

function setRecentPurchaseContent(toast, purchase) {
    const buyer = toast.querySelector('[data-recent-purchase-buyer]');
    const product = toast.querySelector('[data-recent-purchase-product]');
    const packageName = toast.querySelector('[data-recent-purchase-package]');
    const time = toast.querySelector('[data-recent-purchase-time]');
    const quantity = Number(purchase.quantity || 1);
    const packageLabel = quantity > 1
        ? `${purchase.package || 'License'} x${quantity}`
        : (purchase.package || 'License');

    if (buyer) buyer.textContent = purchase.buyer || 'Customer';
    if (product) product.textContent = purchase.product || 'Product';
    if (packageName) packageName.textContent = packageLabel;
    if (time) {
        time.textContent = recentPurchaseRelativeTime(purchase.paid_at, purchase.ago);
    }
}

function initializeRecentPurchaseToast(root = document) {
    clearRecentPurchaseToast();

    const toast = root.querySelector?.('[data-recent-purchase-toast]')
        || document.querySelector('[data-recent-purchase-toast]');

    if (!toast) return;

    toast.hidden = true;
    toast.classList.remove('is-visible');

    if (recentPurchaseSnoozed()) return;

    const endpoint = recentPurchaseFeedUrl(toast);
    const closeButton = toast.querySelector('[data-recent-purchase-close]');
    let purchases = parseRecentPurchaseData(toast);
    let purchasesByKey = new Map(purchases.map((purchase) => [purchase.key, purchase]));
    const knownKeys = new Set();
    const knownKeyOrder = [];
    let priorityKeys = [];
    let closed = false;
    let paused = document.hidden;
    let index = 0;
    let cycleTimer = null;
    let visibleTimer = null;
    let hideTimer = null;
    let pollTimer = null;
    let renderFrame = null;
    let requestController = null;

    const rememberKey = (key) => {
        if (knownKeys.has(key)) return false;

        knownKeys.add(key);
        knownKeyOrder.push(key);

        while (knownKeyOrder.length > RECENT_PURCHASE_MAX_KNOWN_KEYS) {
            knownKeys.delete(knownKeyOrder.shift());
        }

        return true;
    };

    purchases.forEach((purchase) => rememberKey(purchase.key));

    const clearTimer = (timer) => {
        if (timer !== null) window.clearTimeout(timer);
    };

    const clearCycleTimers = () => {
        clearTimer(cycleTimer);
        clearTimer(visibleTimer);
        clearTimer(hideTimer);
        cycleTimer = null;
        visibleTimer = null;
        hideTimer = null;

        if (renderFrame !== null) {
            window.cancelAnimationFrame(renderFrame);
            renderFrame = null;
        }
    };

    const concealToast = (immediately = false) => {
        if (renderFrame !== null) {
            window.cancelAnimationFrame(renderFrame);
            renderFrame = null;
        }

        clearTimer(hideTimer);
        hideTimer = null;
        toast.classList.remove('is-visible');

        if (immediately) {
            toast.hidden = true;
            return;
        }

        hideTimer = window.setTimeout(() => {
            hideTimer = null;

            if (!toast.classList.contains('is-visible')) {
                toast.hidden = true;
            }
        }, 300);
    };

    const revealToast = () => {
        clearTimer(hideTimer);
        hideTimer = null;

        if (renderFrame !== null) {
            window.cancelAnimationFrame(renderFrame);
        }

        toast.style.setProperty('--recent-purchase-duration', `${RECENT_PURCHASE_VISIBLE_MS}ms`);
        toast.hidden = false;
        toast.classList.remove('is-visible');
        void toast.offsetWidth;

        renderFrame = window.requestAnimationFrame(() => {
            renderFrame = null;

            if (!closed && !paused) {
                toast.classList.add('is-visible');
            }
        });
    };

    const nextPurchase = () => {
        while (priorityKeys.length > 0) {
            const priorityPurchase = purchasesByKey.get(priorityKeys.shift());

            if (priorityPurchase) return priorityPurchase;
        }

        if (purchases.length === 0) return null;

        const purchase = purchases[index % purchases.length];
        index = (index + 1) % purchases.length;

        return purchase;
    };

    const cycle = () => {
        cycleTimer = null;

        if (closed || paused || recentPurchaseSnoozed()) return;

        const purchase = nextPurchase();

        if (!purchase) {
            concealToast();
            return;
        }

        setRecentPurchaseContent(toast, purchase);
        revealToast();

        clearTimer(visibleTimer);
        visibleTimer = window.setTimeout(() => {
            visibleTimer = null;
            concealToast();
            cycleTimer = window.setTimeout(
                cycle,
                priorityKeys.length > 0 ? RECENT_PURCHASE_NEW_DELAY_MS : RECENT_PURCHASE_GAP_MS,
            );
        }, RECENT_PURCHASE_VISIBLE_MS);
    };

    const scheduleCycle = (delay) => {
        if (closed || paused || recentPurchaseSnoozed() || visibleTimer !== null) return;

        clearTimer(cycleTimer);
        cycleTimer = window.setTimeout(cycle, delay);
    };

    const applyPurchaseSnapshot = (nextPurchases) => {
        const normalizedPurchases = normalizeRecentPurchases(nextPurchases);
        const nextPurchasesByKey = new Map(
            normalizedPurchases.map((purchase) => [purchase.key, purchase]),
        );
        const newKeys = normalizedPurchases
            .map((purchase) => purchase.key)
            .filter((key) => rememberKey(key));

        purchases = normalizedPurchases;
        purchasesByKey = nextPurchasesByKey;
        index = purchases.length > 0 ? index % purchases.length : 0;
        priorityKeys = [
            ...newKeys,
            ...priorityKeys.filter((key) => nextPurchasesByKey.has(key) && !newKeys.includes(key)),
        ];

        if (purchases.length === 0) {
            clearTimer(cycleTimer);
            cycleTimer = null;

            if (visibleTimer === null) concealToast();

            return;
        }

        if (newKeys.length > 0 && visibleTimer === null) {
            scheduleCycle(RECENT_PURCHASE_NEW_DELAY_MS);
        } else if (cycleTimer === null && visibleTimer === null) {
            scheduleCycle(RECENT_PURCHASE_INITIAL_DELAY_MS);
        }
    };

    const refreshPurchases = async () => {
        if (!endpoint || closed || paused || recentPurchaseSnoozed() || requestController) return;

        const controller = new AbortController();
        requestController = controller;

        try {
            const response = await fetch(endpoint.href, {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: controller.signal,
            });

            if (!response.ok) {
                throw new Error(`Recent purchases refresh failed with status ${response.status}`);
            }

            const payload = await response.json();
            const nextPurchases = Array.isArray(payload) ? payload : payload?.purchases;

            if (!controller.signal.aborted && !closed && !paused) {
                applyPurchaseSnapshot(nextPurchases);
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                // Social proof polling is best-effort and must never interrupt the storefront.
            }
        } finally {
            if (requestController === controller) {
                requestController = null;
            }
        }
    };

    const schedulePoll = (delay = RECENT_PURCHASE_POLL_MS) => {
        clearTimer(pollTimer);
        pollTimer = null;

        if (!endpoint || closed || paused || recentPurchaseSnoozed()) return;

        pollTimer = window.setTimeout(async () => {
            pollTimer = null;
            await refreshPurchases();
            schedulePoll();
        }, delay);
    };

    const pause = () => {
        if (closed) return;

        paused = true;
        clearTimer(pollTimer);
        pollTimer = null;
        clearCycleTimers();

        const controller = requestController;
        controller?.abort();

        if (requestController === controller) {
            requestController = null;
        }

        concealToast(true);
    };

    const resume = async () => {
        if (closed || document.hidden || recentPurchaseSnoozed()) return;

        paused = false;
        await refreshPurchases();

        if (!closed && !paused) {
            if (purchases.length > 0 && cycleTimer === null && visibleTimer === null) {
                scheduleCycle(RECENT_PURCHASE_NEW_DELAY_MS);
            }

            schedulePoll();
        }
    };

    const visibilityChanged = () => {
        if (document.hidden) {
            pause();
        } else {
            resume();
        }
    };

    const pageShown = (event) => {
        if (event.persisted) resume();
    };

    const cleanup = () => {
        if (closed) return;

        closed = true;
        paused = true;
        clearTimer(pollTimer);
        pollTimer = null;
        clearCycleTimers();
        requestController?.abort();
        requestController = null;
        closeButton?.removeEventListener('click', close);
        document.removeEventListener('visibilitychange', visibilityChanged);
        window.removeEventListener('pagehide', pause);
        window.removeEventListener('pageshow', pageShown);
        concealToast(true);
    };

    const close = () => {
        snoozeRecentPurchaseToast();
        cleanup();
    };

    closeButton?.addEventListener('click', close);
    document.addEventListener('visibilitychange', visibilityChanged);
    window.addEventListener('pagehide', pause);
    window.addEventListener('pageshow', pageShown);

    if (!paused && purchases.length > 0) {
        scheduleCycle(RECENT_PURCHASE_INITIAL_DELAY_MS);
    }

    schedulePoll();
    recentPurchaseToastCleanup = cleanup;
}

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

    if (state.overflowSurface) {
        state.overflowSurface.classList.remove('is-select-open');
        state.overflowSurface.style.zIndex = state.previousOverflowSurfaceZIndex || '';
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
    const section = select.closest('.product-section, .license-toolbar');
    const overflowSurface = select.closest('.license-card, .order-mobile-card');

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
        overflowSurface,
        previousOverflowSurfaceZIndex: overflowSurface?.style.zIndex || '',
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

        if (state.overflowSurface) {
            state.overflowSurface.classList.toggle('is-select-open', !isOpen);
            state.overflowSurface.style.zIndex = isOpen ? state.previousOverflowSurfaceZIndex : '90';
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
    initializeMotionEnhancements(root);
};

let mobileMenuOpen = false;
let miniCartAutoCloseTimer = null;
let lastNavbarScroll = window.pageYOffset || 0;
let activeSoftNavigation = null;
let activePageScriptCleanup = null;
let softPageRuntimeSequence = 0;
const ENABLE_AKSA_SAFE_SOFT_NAVIGATION = true;

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
    const navbar = document.getElementById('navbar');

    if (!menu || !button) return;

    mobileMenuOpen = true;
    navbar?.classList.remove('nav-hidden');
    menu.classList.remove('opacity-0', '-translate-y-5', 'pointer-events-none');
    menu.classList.add('opacity-100', 'translate-y-0');
    menu.setAttribute('aria-hidden', 'false');
    button.setAttribute('aria-expanded', 'true');
    setNavButtonLabel(button, 'Close');
}

function closeMobileMenu() {
    const menu = mobileMenu();
    const button = navButton();

    if (!menu || !button) return;

    mobileMenuOpen = false;
    menu.classList.add('opacity-0', '-translate-y-5', 'pointer-events-none');
    menu.classList.remove('opacity-100', 'translate-y-0');
    menu.setAttribute('aria-hidden', 'true');
    button.setAttribute('aria-expanded', 'false');
    setNavButtonLabel(button, 'Menu');
}

function toggleProfileDropdown() {
    const dropdown = document.getElementById('dropdown');
    const toggle = document.querySelector('[data-profile-toggle]');

    if (!dropdown) return;

    const willOpen = dropdown.classList.contains('hidden');
    dropdown.classList.toggle('hidden');
    toggle?.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
}

function closeProfileDropdown() {
    document.getElementById('dropdown')?.classList.add('hidden');
    document.querySelector('[data-profile-toggle]')?.setAttribute('aria-expanded', 'false');
}

function miniCartRoot() {
    return document.querySelector('[data-mini-cart-root]');
}

function miniCartOverlayParts() {
    return {
        panel: document.querySelector('[data-mini-cart-panel]'),
        backdrop: document.querySelector('.mini-cart-backdrop'),
    };
}

function moveMiniCartOverlay(root, open) {
    const { panel, backdrop } = miniCartOverlayParts();

    if (!root || !panel || !backdrop) return;

    if (open && usesMiniCartSheet()) {
        document.body.append(backdrop, panel);
    } else if (!open && panel.parentElement !== root) {
        root.append(backdrop, panel);
    }
}

function setMiniCartOpen(open, { auto = false } = {}) {
    const root = miniCartRoot();
    const trigger = root?.querySelector('[data-mini-cart-trigger]');

    if (!root || !trigger) return;

    if (open) document.getElementById('navbar')?.classList.remove('nav-hidden');

    moveMiniCartOverlay(root, open);

    clearTimeout(miniCartAutoCloseTimer);
    root.classList.toggle('is-open', open);
    root.classList.toggle('is-auto-open', open && auto);
    const { panel, backdrop } = miniCartOverlayParts();
    panel?.classList.toggle('is-visible', open);
    backdrop?.classList.toggle('is-visible', open);
    trigger.setAttribute('aria-expanded', String(open));
    document.body.classList.toggle('mini-cart-sheet-open', open && shouldLockPageForMiniCart());

    if (open && auto) {
        miniCartAutoCloseTimer = window.setTimeout(() => {
            setMiniCartOpen(false);
        }, 2800);
    }
}

function closeMiniCart() {
    setMiniCartOpen(false);
}

async function ensureMiniCartLoaded() {
    const root = miniCartRoot();

    if (!root || root.dataset.miniCartLoaded === 'true' || root.dataset.miniCartLoading === 'true') return;

    root.dataset.miniCartLoading = 'true';

    try {
        const response = await fetch(root.dataset.miniCartUrl, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) throw new Error(data.message || 'Cart preview is unavailable.');

        window.refreshAksaMiniCart?.(data.html, data.cart_count);
        root.dataset.miniCartLoaded = 'true';
    } catch (error) {
        const loading = document.querySelector('[data-mini-cart-content]');
        if (loading) loading.innerHTML = '<p class="mini-cart-load-error">Open the cart to review your items.</p>';
    } finally {
        root.dataset.miniCartLoading = 'false';
    }
}

window.refreshAksaMiniCart = function(html, cartCount, options = {}) {
    const root = miniCartRoot();
    const panel = document.querySelector('[data-mini-cart-panel]');

    if (!root || !panel || typeof html !== 'string') return;

    const currentContent = panel.querySelector('[data-mini-cart-content]');
    const template = document.createElement('template');
    template.innerHTML = html.trim();
    const nextContent = template.content.querySelector('[data-mini-cart-content]');

    if (currentContent && nextContent) {
        nextContent.classList.add('mini-cart-content-enter');
        currentContent.replaceWith(nextContent);
        window.setTimeout(() => nextContent.classList.remove('mini-cart-content-enter'), 420);
    }

    root.querySelector('[data-mini-cart-trigger]')?.setAttribute(
        'aria-label',
        `Open cart with ${Number(cartCount || 0)} items`
    );
    initializeDisplayCurrency(panel);
    root.classList.remove('mini-cart-bump');
    void root.offsetWidth;
    root.classList.add('mini-cart-bump');

    if (options.firstItem) {
        root.classList.remove('mini-cart-first-item');
        void root.offsetWidth;
        root.classList.add('mini-cart-first-item');
        window.setTimeout(() => root.classList.remove('mini-cart-first-item'), 650);
    }

    if (options.autoOpen) setMiniCartOpen(true, { auto: true });
};

window.pulseAksaSuccess = function(button) {
    if (!(button instanceof Element)) return;
    button.classList.remove('aksa-action-success');
    void button.offsetWidth;
    button.classList.add('aksa-action-success');
    window.setTimeout(() => button.classList.remove('aksa-action-success'), 900);
};

window.animateAksaCartTransfer = async function(source) {
    const navbar = document.getElementById('navbar');
    const target = document.querySelector('[data-mini-cart-trigger]');
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    navbar?.classList.remove('nav-hidden');
    if (!(source instanceof Element) || !(target instanceof Element) || reduceMotion) return;

    await new Promise(resolve => window.setTimeout(resolve, 180));
    const sourceRect = source.getBoundingClientRect();
    const targetRect = target.getBoundingClientRect();
    const token = document.createElement('span');
    token.className = 'aksa-cart-fly-token';
    token.textContent = '✦';
    token.style.left = `${sourceRect.left + sourceRect.width / 2}px`;
    token.style.top = `${sourceRect.top + Math.min(sourceRect.height / 2, 42)}px`;
    document.body.appendChild(token);

    const deltaX = targetRect.left + targetRect.width / 2 - (sourceRect.left + sourceRect.width / 2);
    const deltaY = targetRect.top + targetRect.height / 2 - (sourceRect.top + Math.min(sourceRect.height / 2, 42));
    const animation = token.animate([
        { transform: 'translate(-50%, -50%) scale(0.7)', opacity: 0 },
        { transform: `translate(calc(-50% + ${deltaX * 0.46}px), calc(-50% + ${deltaY * 0.18 - 52}px)) scale(1.08)`, opacity: 1, offset: 0.42 },
        { transform: `translate(calc(-50% + ${deltaX}px), calc(-50% + ${deltaY}px)) scale(0.42)`, opacity: 0.2 },
    ], {
        duration: 560,
        easing: 'cubic-bezier(0.22, 1, 0.36, 1)',
        fill: 'forwards',
    });

    await animation.finished.catch(() => {});
    token.remove();
};

function updateNavbarOnScroll() {
    const navbar = document.getElementById('navbar');

    if (!navbar) return;

    const currentScroll = window.pageYOffset;

    if (mobileMenuOpen || miniCartRoot()?.classList.contains('is-open') || miniCartRoot()?.classList.contains('is-auto-open')) {
        navbar.classList.remove('nav-hidden');
        lastNavbarScroll = currentScroll;
        return;
    }

    navbar.classList.toggle('nav-hidden', currentScroll > lastNavbarScroll && currentScroll > 50);
    lastNavbarScroll = currentScroll;
}

function pageContentShell() {
    return document.querySelector('[data-aksa-page-content]');
}

function updateNavGlider(previewLink = null) {
    const menu = document.getElementById('navMenu');
    const glider = menu?.querySelector('[data-nav-glider]');
    const activeLink = previewLink?.closest('#navMenu') === menu
        ? previewLink
        : menu?.querySelector('.nav-item.active');

    if (!menu || !glider || !activeLink) {
        glider?.classList.remove('is-visible');
        return;
    }

    const firstPosition = glider.dataset.ready !== 'true';

    if (firstPosition) glider.style.transition = 'none';
    glider.style.width = `${activeLink.offsetWidth}px`;
    glider.style.transform = `translate3d(${activeLink.offsetLeft}px, 0, 0)`;
    glider.classList.add('is-visible');

    if (firstPosition) {
        glider.dataset.ready = 'true';
        glider.getBoundingClientRect();
        requestAnimationFrame(() => glider.style.removeProperty('transition'));
    }
}

function navItemFromEvent(event) {
    return event.target instanceof Element ? event.target.closest('#navMenu .nav-item') : null;
}

function syncPersistentNavigation(nextDocument) {
    const currentMenu = document.getElementById('navMenu');
    const nextMenu = nextDocument.getElementById('navMenu');

    if (currentMenu && nextMenu) {
        const nextLinks = Array.from(nextMenu.querySelectorAll('.nav-item'));

        currentMenu.querySelectorAll('.nav-item').forEach((link, index) => {
            const active = nextLinks[index]?.classList.contains('active') === true;
            link.classList.toggle('active', active);
            active ? link.setAttribute('aria-current', 'page') : link.removeAttribute('aria-current');
        });
    }

    const currentActions = document.querySelector('[data-navbar-actions]');
    const nextActions = nextDocument.querySelector('[data-navbar-actions]');

    if (currentActions && nextActions) currentActions.replaceWith(nextActions);

    const currentMobileMenu = document.getElementById('mobileMenu');
    const nextMobileMenu = nextDocument.getElementById('mobileMenu');

    if (currentMobileMenu && nextMobileMenu) currentMobileMenu.replaceWith(nextMobileMenu);
}

function samePageHashNavigation(url) {
    return url.origin === window.location.origin
        && url.pathname === window.location.pathname
        && url.search === window.location.search
        && url.hash
        && url.hash !== window.location.hash;
}

function linkAllowsSafeSoftNavigation(link, url) {
    return link.dataset.softNav !== undefined || (
        link.dataset.dashboardRangeLink !== undefined
        && window.location.pathname === '/admin'
        && url.pathname === '/admin'
    );
}

function shouldSoftNavigateLink(link, event) {
    if (!ENABLE_AKSA_SAFE_SOFT_NAVIGATION) return false;
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
    if (!linkAllowsSafeSoftNavigation(link, url)) return false;

    return ![
        '/auth/',
        '/logout',
        '/pay-crypto/',
        '/pay-binance/',
        '/sync-',
        '/cancel-order/',
    ].some((blockedPath) => url.pathname.startsWith(blockedPath));
}

function shouldSoftNavigateForm(form, event) {
    if (!ENABLE_AKSA_SAFE_SOFT_NAVIGATION) return false;
    if (!form || event.defaultPrevented) return false;
    if (form.dataset.noSoftNav !== undefined || form.closest('[data-no-soft-nav]')) return false;
    if (form.dataset.safeSoftNav === undefined) return false;

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

function currentDocumentScriptNonce() {
    const nonceScript = document.querySelector('script[nonce]');

    return nonceScript?.nonce || nonceScript?.getAttribute('nonce') || '';
}

function executeTrackedInlineScript(code, runtime) {
    const runtimeKey = `__aksaSoftPageRuntime${++softPageRuntimeSequence}`;
    const executable = document.createElement('script');
    const nonce = currentDocumentScriptNonce();

    runtime.executed = false;
    runtime.error = null;
    window[runtimeKey] = runtime;

    if (nonce) executable.nonce = nonce;

    executable.textContent = `
        {
            const __aksaSoftPageRuntime = globalThis[${JSON.stringify(runtimeKey)}];
            const window = __aksaSoftPageRuntime.window;
            const document = __aksaSoftPageRuntime.document;
            const setInterval = __aksaSoftPageRuntime.setInterval;
            const clearInterval = __aksaSoftPageRuntime.clearInterval;
            const setTimeout = __aksaSoftPageRuntime.setTimeout;
            const clearTimeout = __aksaSoftPageRuntime.clearTimeout;

            __aksaSoftPageRuntime.executed = true;

            try {
                ${code}
            } catch (error) {
                __aksaSoftPageRuntime.error = error;
            }
        }
    `;

    try {
        document.head.appendChild(executable);

        if (!runtime.executed) {
            throw new Error('Soft page script was blocked by the active Content Security Policy');
        }

        if (runtime.error) throw runtime.error;
    } finally {
        executable.remove();
        delete window[runtimeKey];
        delete runtime.executed;
        delete runtime.error;
    }
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

        executeTrackedInlineScript(code, runtime);
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
    setDashboardChartTooltipText(tooltip, '[data-chart-tooltip-line]', point.dataset.line);
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

        syncPersistentNavigation(nextDocument);
        replaceOptionalShell('[data-aksa-footer-shell]', nextDocument);
        currentContent.replaceWith(nextContent);
        updateDocumentMeta(nextDocument);

        if (options.pushHistory !== false) {
            const currentState = window.history.state || {};

            if (!currentState.aksaSoftNavigation) {
                window.history.replaceState({
                    ...currentState,
                    aksaSoftNavigation: true,
                }, '', window.location.href);
            }

            window.history.pushState({ aksaSoftNavigation: true }, '', nextUrl.href);
        }

        executeSoftPageScripts(nextDocument);
        initializeDisplayCurrency(document);
        initializeCustomSelects(nextContent);
        initializeRecentPurchaseToast(nextContent);
        initializeDownloadAccordions(nextContent);
        initializeMotionEnhancements(nextContent);
        closeMobileMenu();
        closeProfileDropdown();
        scrollAfterSoftNavigation(nextUrl);

        requestAnimationFrame(() => {
            updateNavGlider();
            nextContent.classList.add('aksa-soft-nav-entered');
            window.setTimeout(() => nextContent.classList.remove('aksa-soft-nav-entered'), 380);
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

const DOWNLOAD_ACCORDION_SELECTOR = '[data-download-accordion]';
const DOWNLOAD_ACCORDION_DURATION = 280;
const DOWNLOAD_ACCORDION_EASING = 'cubic-bezier(0.22, 1, 0.36, 1)';

function shouldReduceMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;
}

let productRevealObserver = null;

function initializeMotionEnhancements(root = document) {
    const cards = [...root.querySelectorAll('.product-card-storefront:not([data-motion-reveal-ready])')];

    cards.forEach((card, index) => {
        card.dataset.motionRevealReady = 'true';
        card.style.setProperty('--motion-reveal-delay', `${Math.min(index % 4, 3) * 55}ms`);
    });

    if (cards.length === 0 || shouldReduceMotion() || !('IntersectionObserver' in window)) {
        cards.forEach(card => card.classList.add('is-scroll-revealed'));
        return;
    }

    productRevealObserver ||= new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (!entry.isIntersecting) return;
            entry.target.classList.add('is-scroll-revealed');
            productRevealObserver.unobserve(entry.target);
        });
    }, { rootMargin: '0px 0px -7% 0px', threshold: 0.08 });

    cards.forEach(card => productRevealObserver.observe(card));
}

window.animateAksaValue = function(element, nextText) {
    if (!(element instanceof Element) || element.textContent === nextText) return;

    element.textContent = nextText;

    if (shouldReduceMotion()) return;

    element.classList.remove('aksa-value-change');
    void element.offsetWidth;
    element.classList.add('aksa-value-change');
    window.setTimeout(() => element.classList.remove('aksa-value-change'), 320);
};

function setDownloadAccordionExpanded(accordion, expanded) {
    accordion.querySelector('summary')?.setAttribute('aria-expanded', expanded ? 'true' : 'false');
}

function clearDownloadAccordionAnimation(accordion) {
    accordion._downloadAccordionCleanup?.();
    accordion._downloadAccordionCleanup = null;
    accordion.style.height = '';
    accordion.style.overflow = '';
    accordion.style.transition = '';
    delete accordion.dataset.downloadAccordionAnimating;
}

function animateDownloadAccordionHeight(accordion, startHeight, endHeight, onFinish) {
    if (shouldReduceMotion()) {
        onFinish();
        clearDownloadAccordionAnimation(accordion);
        return;
    }

    let completed = false;

    const finish = (event = null) => {
        if (event && event.target !== accordion) return;
        if (completed) return;

        completed = true;
        accordion.removeEventListener('transitionend', finish);
        window.clearTimeout(timeout);
        accordion._downloadAccordionCleanup = null;
        onFinish();
        clearDownloadAccordionAnimation(accordion);
    };

    const timeout = window.setTimeout(finish, DOWNLOAD_ACCORDION_DURATION + 100);

    accordion.dataset.downloadAccordionAnimating = 'true';
    accordion.style.overflow = 'hidden';
    accordion.style.transition = '';
    accordion.style.height = `${startHeight}px`;
    accordion.addEventListener('transitionend', finish);
    accordion._downloadAccordionCleanup = () => {
        completed = true;
        accordion.removeEventListener('transitionend', finish);
        window.clearTimeout(timeout);
    };

    requestAnimationFrame(() => {
        accordion.style.transition = `height ${DOWNLOAD_ACCORDION_DURATION}ms ${DOWNLOAD_ACCORDION_EASING}`;
        accordion.style.height = `${endHeight}px`;
    });
}

function openDownloadAccordion(accordion) {
    if (accordion.open && accordion.dataset.downloadAccordionAnimating !== 'true') return;

    clearDownloadAccordionAnimation(accordion);
    const startHeight = accordion.offsetHeight;

    accordion.open = true;
    setDownloadAccordionExpanded(accordion, true);

    const endHeight = accordion.offsetHeight;

    animateDownloadAccordionHeight(accordion, startHeight, endHeight, () => {
        accordion.open = true;
        setDownloadAccordionExpanded(accordion, true);
    });
}

function closeDownloadAccordion(accordion) {
    if (!accordion.open && accordion.dataset.downloadAccordionAnimating !== 'true') return;

    clearDownloadAccordionAnimation(accordion);

    if (!accordion.open) return;

    const startHeight = accordion.offsetHeight;
    const summaryHeight = accordion.querySelector('summary')?.offsetHeight || 0;

    setDownloadAccordionExpanded(accordion, false);

    animateDownloadAccordionHeight(accordion, startHeight, summaryHeight, () => {
        accordion.open = false;
        setDownloadAccordionExpanded(accordion, false);
    });
}

function downloadAccordionsFromRoot(root = document) {
    const directMatch = root instanceof Element && root.matches(DOWNLOAD_ACCORDION_SELECTOR) ? [root] : [];
    const nestedMatches = Array.from(root.querySelectorAll?.(DOWNLOAD_ACCORDION_SELECTOR) || []);

    return [...directMatch, ...nestedMatches];
}

function initializeDownloadAccordions(root = document) {
    downloadAccordionsFromRoot(root).forEach((accordion) => {
        if (!(accordion instanceof HTMLDetailsElement)) return;
        if (accordion.dataset.downloadAccordionReady === 'true') return;

        accordion.dataset.downloadAccordionReady = 'true';
        setDownloadAccordionExpanded(accordion, accordion.open);

        accordion.querySelector('summary')?.addEventListener('click', (event) => {
            event.preventDefault();

            if (accordion.open) {
                closeDownloadAccordion(accordion);
                return;
            }

            const group = accordion.closest('[data-download-accordion-group]') || document;

            group.querySelectorAll(DOWNLOAD_ACCORDION_SELECTOR).forEach((otherAccordion) => {
                if (otherAccordion !== accordion) {
                    closeDownloadAccordion(otherAccordion);
                }
            });

            openDownloadAccordion(accordion);
        });
    });
}

function initializeGlobalPageEnhancements(root = document) {
    initializeDisplayCurrency(root);
    initializeCustomSelects(root);
    initializeRecentPurchaseToast(root);
    initializeDownloadAccordions(root);
    initializeMotionEnhancements(root);
    updateNavGlider();

    const pageContent = root === document
        ? document.querySelector('[data-aksa-page-content]')
        : root.closest?.('[data-aksa-page-content]') || root.querySelector?.('[data-aksa-page-content]');
    if (pageContent && pageContent.dataset.pageEntered !== 'true') {
        pageContent.dataset.pageEntered = 'true';
        pageContent.classList.add('aksa-page-entered');
        window.setTimeout(() => pageContent.classList.remove('aksa-page-entered'), 420);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initializeGlobalPageEnhancements());
} else {
    initializeGlobalPageEnhancements();
}

document.addEventListener('click', (event) => {
    const currencyOption = event.target.closest('[data-currency-option]');

    if (currencyOption) {
        event.preventDefault();
        window.setAksaDisplayCurrency(currencyOption.dataset.currencyOption, {
            source: 'manual',
        });
        return;
    }

    const mobileToggle = event.target.closest('[data-mobile-menu-toggle]');

    if (mobileToggle) {
        event.stopPropagation();
        mobileMenuOpen ? closeMobileMenu() : openMobileMenu();
        return;
    }

    if (event.target.closest('[data-mobile-menu-link]')) {
        closeMobileMenu();
    }

    const miniCartTrigger = event.target.closest('[data-mini-cart-trigger]');

    if (miniCartTrigger && usesMiniCartSheet()) {
        event.preventDefault();
        event.stopPropagation();
        setMiniCartOpen(!miniCartRoot()?.classList.contains('is-open'));
        ensureMiniCartLoaded();
        closeMobileMenu();
        closeProfileDropdown();
        return;
    }

    if (event.target.closest('[data-mini-cart-close]')) {
        event.preventDefault();
        closeMiniCart();
        return;
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

    if (!event.target.closest('[data-mini-cart-root]')) {
        closeMiniCart();
    }
});

document.addEventListener('keydown', (event) => {
    const modal = activePaymentModal();

    if (modal && event.key === 'Escape') {
        event.preventDefault();
        closePaymentModal(modal);
        return;
    }

    if (modal && event.key === 'Tab') {
        const focusable = modalFocusableElements(modal);

        if (focusable.length === 0) {
            event.preventDefault();
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }

        return;
    }

    if (event.key !== 'Escape') return;

    closeMobileMenu();
    closeProfileDropdown();
    closeMiniCart();
});

window.addEventListener('resize', () => {
    if (window.innerWidth >= 1280 && mobileMenuOpen) {
        closeMobileMenu();
    }

    if (!usesMiniCartSheet()) closeMiniCart();

    updateNavGlider();
}, { passive: true });

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

window.addEventListener('popstate', (event) => {
    if (!ENABLE_AKSA_SAFE_SOFT_NAVIGATION || !event.state?.aksaSoftNavigation) return;

    softNavigate(window.location.href, {
        pushHistory: false,
    });
});

document.addEventListener('pointerover', (event) => {
    if (event.target.closest('[data-mini-cart-root]') && !usesMiniCartSheet()) {
        ensureMiniCartLoaded();
    }

    const navItem = navItemFromEvent(event);

    if (navItem) updateNavGlider(navItem);

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
    const navItem = navItemFromEvent(event);
    const nextNavItem = event.relatedTarget instanceof Element
        ? event.relatedTarget.closest('#navMenu .nav-item')
        : null;

    if (navItem && nextNavItem !== navItem) updateNavGlider();

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
    if (event.target.closest('[data-mini-cart-root]')) ensureMiniCartLoaded();

    const navItem = navItemFromEvent(event);

    if (navItem) updateNavGlider(navItem);

    const point = dashboardChartPointFromEvent(event);

    if (point) {
        showDashboardChartTooltip(point);
    }
});

document.addEventListener('focusout', (event) => {
    const navItem = navItemFromEvent(event);

    if (navItem) updateNavGlider();

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

document.addEventListener('aksa:before-page-swap', () => {
    hideDashboardChartTooltip();
    clearRecentPurchaseToast();
    closeMiniCart();
});

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

document.addEventListener('submit', (event) => {
    const form = event.target.closest('[data-cart-remove-form]');
    const row = form?.closest('[data-cart-item]');

    if (!form || !row || event.defaultPrevented || form.dataset.motionSubmitted === 'true' || shouldReduceMotion()) return;

    event.preventDefault();
    form.dataset.motionSubmitted = 'true';
    row.classList.add('cart-item-removing');
    window.setTimeout(() => form.submit(), 280);
}, true);

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-cart-quantity-form]');
    const submitter = event.submitter;
    const row = form?.closest('[data-cart-item]');

    if (!form || !row || !(submitter instanceof HTMLButtonElement) || event.defaultPrevented) return;

    event.preventDefault();
    if (form.dataset.cartUpdating === 'true') return;

    const buttons = [...form.querySelectorAll('button[type="submit"]')];
    const previousDisabled = buttons.map(button => button.disabled);
    const body = new FormData(form);
    body.set(submitter.name, submitter.value);
    form.dataset.cartUpdating = 'true';
    buttons.forEach(button => { button.disabled = true; });
    row.classList.add('cart-item-updating');

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

        if (!response.ok) throw new Error(data.message || 'Cart quantity could not be updated.');

        const item = data.item || {};
        const cart = data.cart || {};
        const quantity = Number(item.quantity || 1);
        const maxQuantity = Number(item.max_quantity || quantity);
        const quantityOutput = form.querySelector('.quantity-stepper-value');
        window.animateAksaValue?.(quantityOutput, String(quantity));

        const lineTotal = row.querySelector('[data-cart-line-total]');
        if (lineTotal) {
            lineTotal.dataset.priceIdr = String(item.line_total_idr ?? 0);
            lineTotal.dataset.priceUsd = String(item.line_total_usdt ?? '');
            window.refreshAksaDisplayCurrency?.(row);
            lineTotal.classList.remove('aksa-value-change');
            void lineTotal.offsetWidth;
            lineTotal.classList.add('aksa-value-change');
        }

        const decrement = form.querySelector('[data-cart-quantity-direction="down"]');
        const increment = form.querySelector('[data-cart-quantity-direction="up"]');
        if (decrement) {
            decrement.value = String(Math.max(1, quantity - 1));
            decrement.disabled = quantity <= 1;
        }
        if (increment) {
            increment.value = String(quantity + 1);
            increment.disabled = quantity >= maxQuantity;
        }

        (cart.item_limits || []).forEach(limit => {
            const limitRow = document.querySelector(`[data-cart-item="${Number(limit.id)}"]`);
            const limitForm = limitRow?.querySelector('[data-cart-quantity-form]');
            const plus = limitForm?.querySelector('[data-cart-quantity-direction="up"]');
            const current = Number(limitForm?.querySelector('.quantity-stepper-value')?.textContent || 1);
            if (plus) plus.disabled = current >= Number(limit.max_quantity || 1);
        });

        const distinctItems = Number(cart.distinct_items || 0);
        const totalQuantity = Number(cart.quantity || 0);
        const bundleCount = document.getElementById('cartBundleCount');
        window.animateAksaValue?.(
            bundleCount,
            `${distinctItems} ${distinctItems === 1 ? 'package' : 'packages'} · ${totalQuantity} ${totalQuantity === 1 ? 'license' : 'licenses'}`
        );

        const subtotal = document.querySelector('[data-cart-subtotal]');
        if (subtotal) {
            subtotal.dataset.priceIdr = String(cart.subtotal_idr ?? 0);
            subtotal.dataset.priceUsd = String(cart.subtotal_usdt ?? '');
            window.refreshAksaDisplayCurrency?.(subtotal.parentElement || document);
            subtotal.classList.remove('aksa-value-change');
            void subtotal.offsetWidth;
            subtotal.classList.add('aksa-value-change');
        }

        document.querySelectorAll('[data-cart-count]').forEach(badge => {
            badge.textContent = String(totalQuantity);
            badge.classList.toggle('hidden', totalQuantity <= 0);
        });

        const miniCart = miniCartRoot();
        if (miniCart) miniCart.dataset.miniCartLoaded = 'false';
    } catch (error) {
        previousDisabled.forEach((disabled, index) => { buttons[index].disabled = disabled; });
        window.showAppToast?.('Cart not updated', error.message || 'Refresh the page and try again.', {
            variant: 'error',
        });
    } finally {
        form.dataset.cartUpdating = 'false';
        row.classList.remove('cart-item-updating');
    }
}, true);

document.addEventListener('change', (event) => {
    const control = event.target.closest('#checkoutForm input[type="radio"]');

    if (!control || shouldReduceMotion()) return;

    const section = control.closest('.product-section');
    const summary = document.getElementById('checkoutFinalSummary');

    [section, summary].forEach(element => {
        if (!element) return;
        element.classList.remove('checkout-step-transition');
        void element.offsetWidth;
        element.classList.add('checkout-step-transition');
        window.setTimeout(() => element.classList.remove('checkout-step-transition'), 380);
    });
});

document.addEventListener('submit', (event) => {
    const form = event.target.closest('[data-license-reset-form]');

    if (!form || event.defaultPrevented) return;

    const button = form.querySelector('button[type="submit"]');

    if (!button) return;

    button.disabled = true;
    setButtonLabel(button, 'Resetting...');
    button.classList.add('opacity-60', 'pointer-events-none');
});

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

function filterLicenseGroups() {
    const search = String(document.getElementById('licenseSearch')?.value || '').trim().toLowerCase();
    const product = String(document.getElementById('licenseProductFilter')?.value || '');

    document.querySelectorAll('[data-license-group]').forEach(group => {
        const matchesSearch = !search || String(group.dataset.licenseSearch || '').includes(search);
        const matchesProduct = !product || group.dataset.licenseProduct === product;
        group.classList.toggle('hidden', !matchesSearch || !matchesProduct);
    });
}

document.addEventListener('input', (event) => {
    if (event.target.matches('#licenseSearch')) filterLicenseGroups();

    if (event.target.matches('#downloadSearch')) {
        const search = String(event.target.value || '').trim().toLowerCase();
        document.querySelectorAll('[data-download-search]').forEach(card => {
            card.classList.toggle('hidden', search && !String(card.dataset.downloadSearch || '').includes(search));
        });
    }
});

document.addEventListener('change', (event) => {
    if (event.target.matches('#licenseProductFilter')) filterLicenseGroups();
});

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-order-filter]');
    const root = button?.closest('#ordersContent');
    if (!button || !root) return;

    const filter = button.dataset.orderFilter || 'active';
    let visibleCount = 0;
    root.querySelectorAll('[data-order-entry]').forEach(entry => {
        const status = entry.dataset.orderStatus;
        const visible = filter === 'all' ||
            (filter === 'active' && status === 'pending') ||
            (filter === 'previous' && status !== 'pending') ||
            status === filter;
        entry.classList.toggle('hidden', !visible);
        if (visible) visibleCount += 1;
    });
    root.querySelector('[data-order-filter-empty]')?.classList.toggle('hidden', visibleCount > 0);
    root.querySelectorAll('[data-order-filter]').forEach(option => {
        const selected = option === button;
        option.classList.toggle('active', selected);
        option.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });
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
    const revealButton = event.target.closest('[data-reveal-license]');

    if (revealButton) {
        const key = document.getElementById(`key-${revealButton.dataset.revealLicense}`);
        const fullValue = key?.dataset.licenseKeyValue || '';
        const masked = key?.dataset.licenseMasked === 'true';

        if (key && fullValue) {
            clearTimeout(revealButton._licenseHideTimeout);
            key.textContent = masked
                ? fullValue
                : (fullValue.length > 8
                    ? `${fullValue.slice(0, 4)}${'•'.repeat(fullValue.length - 8)}${fullValue.slice(-4)}`
                    : '•'.repeat(Math.max(4, fullValue.length)));
            key.dataset.licenseMasked = masked ? 'false' : 'true';
            revealButton.setAttribute('aria-pressed', masked ? 'true' : 'false');
            setButtonLabel(revealButton, masked ? 'Hide' : 'Reveal');

            if (masked) {
                revealButton._licenseHideTimeout = setTimeout(() => {
                    const latestValue = key.dataset.licenseKeyValue || '';
                    key.textContent = latestValue.length > 8
                        ? `${latestValue.slice(0, 4)}${'•'.repeat(latestValue.length - 8)}${latestValue.slice(-4)}`
                        : '•'.repeat(Math.max(4, latestValue.length));
                    key.dataset.licenseMasked = 'true';
                    revealButton.setAttribute('aria-pressed', 'false');
                    setButtonLabel(revealButton, 'Reveal');
                }, 30000);
            }
        }
        return;
    }

    const button = event.target.closest('[data-copy-license]');

    if (!button) return;

    const key = document.getElementById(`key-${button.dataset.copyLicense}`);
    const text = key?.dataset.licenseKeyValue?.trim();

    if (!text) return;

    const originalText = getButtonLabel(button);

    try {
        await navigator.clipboard.writeText(text);
        setButtonLabel(button, 'Copied ✓');
        button.classList.add('text-green-400');
        button.classList.add('is-copy-success');
        const licenseBox = button.closest('.license-key-box');
        licenseBox?.classList.remove('license-copy-success');
        if (licenseBox) void licenseBox.offsetWidth;
        licenseBox?.classList.add('license-copy-success');
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
            button.classList.remove('is-copy-success');
            button.closest('.license-key-box')?.classList.remove('license-copy-success');
        }, 1800);
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
        setButtonLabel(button, 'Copied ✓');
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
        }, 1800);
    }
});

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-license-show-all]');
    const group = button?.closest('[data-license-group]');

    if (!button || !group) return;

    const expanded = button.getAttribute('aria-expanded') === 'true';
    group.querySelectorAll('[data-license-extra]').forEach(row => row.classList.toggle('hidden', expanded));
    button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    setButtonLabel(button, expanded ? button.dataset.collapsedLabel : 'Show less');
    button.querySelector('[data-show-all-chevron]')?.classList.toggle('rotate-180', !expanded);
});
