import { setButtonLabel } from './ui.js';
import { shouldReduceMotion, usesTouchMotion } from './motion-preferences.js';

const DOWNLOAD_ACCORDION_SELECTOR = '[data-download-accordion]';

const DOWNLOAD_ACCORDION_DURATION = 400;

const DOWNLOAD_ACCORDION_EASING = 'cubic-bezier(0.22, 1, 0.36, 1)';

let scrollRevealObserver = null;

let homeStagedRevealTimer = null;

let homeCountUpTimer = null;

let homeCountUpFrame = null;

function homeContentWithin(root = document) {
    if (root instanceof Element && root.matches('[data-aksa-home-content]')) return root;

    return root.querySelector?.('[data-aksa-home-content]') || null;
}

function stopHomeCountUps() {
    window.clearTimeout(homeCountUpTimer);
    window.cancelAnimationFrame(homeCountUpFrame);
    homeCountUpTimer = null;
    homeCountUpFrame = null;
}

function startHomeCountUps(content) {
    const counters = [...content.querySelectorAll('[data-home-count-up]:not([data-home-counted])')];

    if (counters.length === 0) return;

    const finish = () => {
        counters.forEach((counter) => {
            const target = Math.max(0, Number.parseInt(counter.dataset.homeCountUp || '0', 10) || 0);
            counter.textContent = `${target}${counter.dataset.homeCountUpSuffix || ''}`;
            counter.dataset.homeCounted = 'true';
            counter.dataset.homeCountUpState = 'complete';
        });
    };

    stopHomeCountUps();

    if (shouldReduceMotion()) {
        finish();
        return;
    }

    const touchMotion = usesTouchMotion();
    const widths = counters.map(counter => Math.ceil(counter.getBoundingClientRect().width));
    const targets = counters.map(counter => Math.max(0, Number.parseInt(counter.dataset.homeCountUp || '0', 10) || 0));
    counters.forEach((counter, index) => {
        counter.style.setProperty('--home-count-up-width', `${widths[index]}px`);
        counter.textContent = `0${counter.dataset.homeCountUpSuffix || ''}`;
        counter.dataset.homeCountUpState = 'waiting';
    });

    homeCountUpTimer = window.setTimeout(() => {
        const startedAt = window.performance.now();
        const duration = touchMotion ? 600 : 1180;
        let lastPaint = -Infinity;

        counters.forEach(counter => counter.dataset.homeCountUpState = 'running');

        const tick = (now) => {
            const progress = Math.min(1, (now - startedAt) / duration);
            if (touchMotion && now - lastPaint < 32 && progress < 1) {
                homeCountUpFrame = window.requestAnimationFrame(tick);
                return;
            }
            lastPaint = now;
            const eased = 1 - ((1 - progress) ** 3);

            counters.forEach((counter, index) => {
                const suffix = counter.dataset.homeCountUpSuffix || '';
                const nextText = `${Math.round(targets[index] * eased)}${suffix}`;
                if (counter.textContent !== nextText) counter.textContent = nextText;
            });

            if (progress < 1) {
                homeCountUpFrame = window.requestAnimationFrame(tick);
                return;
            }

            homeCountUpFrame = null;
            finish();
        };

        homeCountUpTimer = null;
        homeCountUpFrame = window.requestAnimationFrame(tick);
    }, touchMotion ? 1600 : 360);
}

function beginHomeStagedReveal(root = document) {
    const homeContent = homeContentWithin(root);

    if (!homeContent) return;

    // Only apply the staged entrance to pages that include the homepage intro.
    if (!document.documentElement.hasAttribute('data-aksa-intro-page')) return;

    const documentRoot = document.documentElement;
    window.clearTimeout(homeStagedRevealTimer);
    documentRoot.classList.remove('aksa-home-reveal-ready');
    documentRoot.classList.remove('aksa-home-reveal-consumed');
    startHomeCountUps(homeContent);

    window.requestAnimationFrame(() => {
        documentRoot.classList.add('aksa-home-reveal-ready');
        homeStagedRevealTimer = window.setTimeout(() => {
            documentRoot.classList.remove('aksa-home-reveal-ready');
            documentRoot.classList.add('aksa-home-reveal-consumed');
            homeStagedRevealTimer = null;
        }, usesTouchMotion() ? 1400 : 2800);
    });
}

function initializeMotionEnhancements(root = document) {
    const items = [...root.querySelectorAll('[data-scroll-reveal]:not([data-motion-reveal-ready])')];
    const visibleItems = new Set(items.filter(item => {
        const bounds = item.getBoundingClientRect();
        return bounds.top < window.innerHeight && bounds.bottom > 0;
    }));

    items.forEach((item, index) => {
        item.dataset.motionRevealReady = 'true';
        item.dataset.motionRevealFrom = 'bottom';
        item.style.setProperty('--motion-reveal-delay', `${Math.min(index % 4, 3) * 45}ms`);
        // Content already in view must not disappear while the observer starts.
        if (visibleItems.has(item)) {
            item.classList.add('is-scroll-revealed');
        }
    });

    if (items.length === 0 || shouldReduceMotion() || !('IntersectionObserver' in window)) {
        items.forEach(item => item.classList.add('is-scroll-revealed'));
        return;
    }

    scrollRevealObserver ||= new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-scroll-revealed');
                if (usesTouchMotion()) observer.unobserve(entry.target);
                return;
            }

            // Once visible on touch devices, keep the content stable on return.
            if (usesTouchMotion() && entry.target.classList.contains('is-scroll-revealed')) {
                observer.unobserve(entry.target);
                return;
            }

            const bounds = entry.boundingClientRect;

            if (bounds.bottom <= 0) {
                entry.target.dataset.motionRevealFrom = 'top';
                entry.target.classList.remove('is-scroll-revealed');
            } else if (bounds.top >= window.innerHeight) {
                entry.target.dataset.motionRevealFrom = 'bottom';
                entry.target.classList.remove('is-scroll-revealed');
            }
        });
    }, { threshold: [0, 0.08] });

    items.forEach(item => {
        if (!usesTouchMotion() || !visibleItems.has(item)) scrollRevealObserver.observe(item);
    });
}

function setDownloadAccordionExpanded(accordion, expanded) {
    accordion.dataset.downloadExpanded = expanded ? 'true' : 'false';
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

function updateCategoryFilterGlider(selectedChip = null) {
    const row = selectedChip?.closest?.('[data-category-filter-row]')
        || document.querySelector('[data-category-filter-row]');
    const glider = row?.querySelector('[data-category-filter-glider]');
    const activeChip = selectedChip || row?.querySelector('[data-category-filter][aria-pressed="true"]');

    if (!row || !glider || !activeChip) {
        glider?.classList.remove('is-visible');
        return;
    }

    glider.style.width = `${activeChip.offsetWidth}px`;
    glider.style.transform = `translateX(${activeChip.offsetLeft}px)`;
    row.classList.add('is-glider-ready');
    glider.classList.add('is-visible');
}

export { beginHomeStagedReveal, initializeMotionEnhancements, initializeDownloadAccordions, updateCategoryFilterGlider };

export function registerMotion() {
    window.pulseAksaSuccess = function(button) {
        if (!(button instanceof Element)) return;
        button.classList.remove('aksa-action-success');
        void button.offsetWidth;
        button.classList.add('aksa-action-success');
        window.setTimeout(() => button.classList.remove('aksa-action-success'), 900);
    };

    window.animateAksaCheckoutSuccess = async function(submit, summary) {
        if (!(submit instanceof Element)) return;

        submit.classList.add('checkout-submit-success');
        setButtonLabel(submit, 'Invoice Ready');
        summary?.classList.remove('checkout-summary-success');
        if (summary) void summary.offsetWidth;
        summary?.classList.add('checkout-summary-success');

        await new Promise(resolve => window.setTimeout(
            resolve,
            shouldReduceMotion() ? 0 : 680
        ));
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

    document.addEventListener('aksa:before-page-swap', () => {
        scrollRevealObserver?.disconnect();
        scrollRevealObserver = null;
        window.clearTimeout(homeStagedRevealTimer);
        stopHomeCountUps();
        homeStagedRevealTimer = null;
        document.documentElement.classList.remove('aksa-home-reveal-ready', 'aksa-home-reveal-consumed');
    });

    window.animateAksaValue = function(element, nextText, options = {}) {
        if (!(element instanceof Element)) return;

        const renderedText = element.textContent?.trim() || '';
        const previousText = String(options.previousText ?? renderedText).trim();
        const normalizedNextText = String(nextText);

        if (renderedText === normalizedNextText && previousText === normalizedNextText) return;

        element.dataset.aksaPreviousValue = previousText;
        element.textContent = normalizedNextText;

        if (shouldReduceMotion()) {
            delete element.dataset.aksaPreviousValue;
            return;
        }

        element.classList.remove('aksa-value-change');
        void element.offsetWidth;
        element.classList.add('aksa-value-change');
        window.setTimeout(() => {
            element.classList.remove('aksa-value-change');
            delete element.dataset.aksaPreviousValue;
        }, 520);
    };

    window.updateAksaCategoryGlider = updateCategoryFilterGlider;
}
