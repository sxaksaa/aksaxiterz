import { formatIdr } from './ui.js';
import { shouldReduceMotion } from './motion-preferences.js';

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

let displayCurrencyAnimationSequence = 0;

let displayCurrencyAnimationTimers = [];

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

function formatDisplayUsd(amount, maximumFractionDigits = 4) {
    const numericAmount = Number(amount || 0);

    return `$${numericAmount.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits,
    })}`;
}

function formatDisplayPrice(idrAmount, usdAmount, currency = activeDisplayCurrency) {
    return normalizedDisplayCurrency(currency) === 'usd'
        ? formatDisplayUsd(usdAmount)
        : formatIdr(idrAmount);
}

function displayPriceText(element, currency) {
    const idrAmount = element.dataset.priceIdr;
    const usdAmount = element.dataset.priceUsd;

    if (idrAmount === undefined) return null;

    const prefix = element.dataset.pricePrefix || '';
    const suffix = element.dataset.priceSuffix || '';
    const formattedPrice = currency === 'usd' && element.dataset.priceDecimals === '2'
        ? formatDisplayUsd(usdAmount, 2)
        : formatDisplayPrice(idrAmount, usdAmount, currency);

    return currency === 'usd' && (usdAmount === undefined || usdAmount === '')
        ? (element.dataset.priceUsdFallback || 'USD unavailable')
        : `${prefix}${formattedPrice}${suffix}`;
}

function syncDisplayCurrencyContent(scope, currency) {
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
}

function syncDisplayCurrencyControls(currency) {
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

function refreshDisplayCurrency(root = document) {
    const currency = activeDisplayCurrency || detectedDisplayCurrency();
    const scope = root?.querySelectorAll ? root : document;

    scope.querySelectorAll('[data-display-price]').forEach(element => {
        const nextText = displayPriceText(element, currency);

        if (nextText === null) return;

        element.textContent = nextText;
    });

    syncDisplayCurrencyContent(scope, currency);
    syncDisplayCurrencyControls(currency);
}

function clearDisplayCurrencyAnimation() {
    displayCurrencyAnimationSequence += 1;
    displayCurrencyAnimationTimers.forEach(timer => window.clearTimeout(timer));
    displayCurrencyAnimationTimers = [];

    document.querySelectorAll('[data-display-price]').forEach(element => {
        element.classList.remove('aksa-currency-price-waiting', 'aksa-currency-price-leaving', 'aksa-currency-price-entering');
        element.style.removeProperty('--aksa-currency-price-width');
        delete element.dataset.currencySwapState;
    });

    document.querySelectorAll('[data-currency-switcher]')
        .forEach(switcher => switcher.classList.remove('is-currency-switching'));
}

function measureDisplayPriceWidths(prices) {
    const measurements = prices.map(({ element, nextText }) => {
        const currentWidth = element.getBoundingClientRect().width;
        const computed = window.getComputedStyle(element);
        const probe = document.createElement('span');
        probe.textContent = nextText;
        Object.assign(probe.style, {
            position: 'fixed', left: '-10000px', top: '0', visibility: 'hidden',
            whiteSpace: 'nowrap', font: computed.font,
            fontFeatureSettings: computed.fontFeatureSettings,
            fontKerning: computed.fontKerning, letterSpacing: computed.letterSpacing,
        });
        return { currentWidth, probe };
    });
    const probes = document.createElement('div');
    measurements.forEach(({ probe }) => probes.appendChild(probe));
    document.body.appendChild(probes);
    const widths = measurements.map(({ currentWidth, probe }) => Math.ceil(Math.max(currentWidth, probe.getBoundingClientRect().width)));
    probes.remove();
    return widths;
}

function animateDisplayCurrencyChange(currency, onComplete) {
    clearDisplayCurrencyAnimation();
    const sequence = displayCurrencyAnimationSequence;
    const prices = [...document.querySelectorAll('[data-display-price]')]
        .filter(element => element.getClientRects().length > 0)
        .map(element => ({ element, nextText: displayPriceText(element, currency) }))
        .filter(item => item.nextText !== null && item.element.textContent !== item.nextText);

    document.querySelectorAll('[data-currency-switcher]').forEach(switcher => {
        switcher.classList.add('is-currency-switching');
    });

    if (prices.length === 0) {
        refreshDisplayCurrency(document);
        onComplete();
        return;
    }

    const schedule = (callback, delay) => {
        const timer = window.setTimeout(() => {
            if (sequence === displayCurrencyAnimationSequence) callback();
        }, delay);
        displayCurrencyAnimationTimers.push(timer);
    };
    let completionDelay = 0;

    const widths = measureDisplayPriceWidths(prices);
    prices.forEach(({ element, nextText }, index) => {
        const stagger = Math.min(index, 6) * 45;
        const leaveAt = 110 + stagger;
        const enterAt = leaveAt + 170;
        const finishAt = enterAt + 340;

        element.style.setProperty('--aksa-currency-price-width', `${widths[index]}px`);
        element.classList.add('aksa-currency-price-waiting');
        element.dataset.currencySwapState = 'waiting';

        schedule(() => {
            element.classList.add('aksa-currency-price-leaving');
            element.dataset.currencySwapState = 'leaving';
        }, leaveAt);
        schedule(() => {
            element.textContent = nextText;
            element.classList.remove('aksa-currency-price-leaving');
            element.classList.add('aksa-currency-price-entering');
            element.dataset.currencySwapState = 'entering';
        }, enterAt);
        schedule(() => {
            element.classList.remove('aksa-currency-price-waiting', 'aksa-currency-price-entering');
            element.style.removeProperty('--aksa-currency-price-width');
            element.dataset.currencySwapState = 'complete';
        }, finishAt);

        completionDelay = Math.max(completionDelay, finishAt);
    });

    schedule(() => syncDisplayCurrencyContent(document, currency), 280);
    schedule(() => {
        document.querySelectorAll('[data-currency-switcher]')
            .forEach(switcher => switcher.classList.remove('is-currency-switching'));
        onComplete();
    }, completionDelay);
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

export { initializeDisplayCurrency };

export function registerCurrency() {
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

        const previousCurrency = activeDisplayCurrency || detectedDisplayCurrency();

        if (normalized === previousCurrency) {
            refreshDisplayCurrency(document);
            return true;
        }

        activeDisplayCurrency = normalized;

        if (options.persist !== false) {
            try {
                window.localStorage.setItem(DISPLAY_CURRENCY_STORAGE_KEY, normalized);
            } catch (error) {
                // The selection still applies to this page when storage is unavailable.
            }
        }

        syncDisplayCurrencyControls(normalized);

        const completeChange = () => notifyDisplayCurrencyChanged(options.source || 'manual');
        const shouldAnimate = options.animate !== false
            && options.source === 'manual'
            && !shouldReduceMotion();

        if (shouldAnimate) {
            animateDisplayCurrencyChange(normalized, completeChange);
        } else {
            clearDisplayCurrencyAnimation();
            refreshDisplayCurrency(document);
            completeChange();
        }

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
}
