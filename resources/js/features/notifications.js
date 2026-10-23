import { shouldReduceMotion, usesTouchMotion } from './motion-preferences.js';

let appToastTimer = null;

let recentPurchaseToastCleanup = null;

const RECENT_PURCHASE_SNOOZE_KEY = 'aksa_recent_purchase_snoozed_until';

const RECENT_PURCHASE_VISIBLE_MS = window.matchMedia('(max-width: 639px)').matches ? 5200 : 8200;

const RECENT_PURCHASE_GAP_MS = 14500;

const RECENT_PURCHASE_INITIAL_DELAY_MS = 1800;

const RECENT_PURCHASE_HOME_INITIAL_DELAY_MS = 5200;

const RECENT_PURCHASE_NEW_DELAY_MS = 350;

const RECENT_PURCHASE_POLL_MS = 20000;

const RECENT_PURCHASE_MAX_KNOWN_KEYS = 100;

const RECENT_PURCHASE_EXIT_MS = 560;

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
    const initialDelay = toast.closest('[data-aksa-home-content]')
        ? RECENT_PURCHASE_HOME_INITIAL_DELAY_MS
        : RECENT_PURCHASE_INITIAL_DELAY_MS;
    const firstTouchAppearance = usesTouchMotion() ? performance.now() + initialDelay : 0;
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
        const wasVisible = toast.classList.contains('is-visible');
        toast.classList.remove('is-visible');

        if (immediately) {
            toast.classList.remove('is-leaving');
            toast.hidden = true;
            return;
        }

        toast.classList.toggle('is-leaving', wasVisible && !shouldReduceMotion());

        hideTimer = window.setTimeout(() => {
            hideTimer = null;

            if (!toast.classList.contains('is-visible')) {
                toast.classList.remove('is-leaving');
                toast.hidden = true;
            }
        }, shouldReduceMotion() ? 0 : RECENT_PURCHASE_EXIT_MS);
    };

    const revealToast = () => {
        clearTimer(hideTimer);
        hideTimer = null;

        if (renderFrame !== null) {
            window.cancelAnimationFrame(renderFrame);
        }

        toast.style.setProperty('--recent-purchase-duration', `${RECENT_PURCHASE_VISIBLE_MS}ms`);
        toast.hidden = false;
        toast.classList.remove('is-visible', 'is-leaving');
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
        cycleTimer = window.setTimeout(cycle, Math.max(delay, firstTouchAppearance - performance.now()));
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
            scheduleCycle(initialDelay);
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

    const cleanup = (immediately = true) => {
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
        concealToast(immediately);
    };

    const close = () => {
        snoozeRecentPurchaseToast();
        cleanup(false);
    };

    closeButton?.addEventListener('click', close);
    document.addEventListener('visibilitychange', visibilityChanged);
    window.addEventListener('pagehide', pause);
    window.addEventListener('pageshow', pageShown);

    if (!paused && purchases.length > 0) {
        scheduleCycle(initialDelay);
    }

    schedulePoll();
    recentPurchaseToastCleanup = cleanup;
}

export { clearRecentPurchaseToast, initializeRecentPurchaseToast };

export function registerNotifications() {
    window.showAppToast = function(title, message = '', options = {}) {
        const toast = document.getElementById('appToast');
        const toastTitle = document.getElementById('appToastTitle');
        const toastMessage = document.getElementById('appToastMessage');
        const toastAction = document.getElementById('appToastAction');

        if (!toast || !toastTitle || !toastMessage) return;

        const variant = options.variant || 'info';
        const duration = Number(options.duration || 3400);
        const redirectDelay = Number(options.redirectDelay || 900);
        const visibleDuration = options.redirectAfter ? redirectDelay : duration;

        toast.dataset.variant = variant;
        toastTitle.innerText = title;
        toastMessage.innerText = message;
        if (toastAction) {
            toastAction.classList.toggle('hidden', !options.actionLabel);
            toastAction.textContent = options.actionLabel || '';
            toastAction.onclick = typeof options.onAction === 'function'
                ? () => {
                    clearTimeout(appToastTimer);
                    options.onAction();
                    toast.classList.remove('is-visible');
                }
                : null;
        }
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
}
