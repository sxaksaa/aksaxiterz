import { initializeCheckout } from './checkout.js';
import { setButtonLabel } from './ui.js';
import { initializeDisplayCurrency } from './currency.js';
import { initializeRecentPurchaseToast } from './notifications.js';
import { initializeCustomSelects } from './forms.js';
import { miniCartRoot } from './cart.js';
import { beginHomeStagedReveal, initializeMotionEnhancements, initializeDownloadAccordions } from './motion.js';

let mobileMenuOpen = false;

let lastNavbarScroll = window.pageYOffset || 0;

let activeSoftNavigation = null;

let activePageScriptCleanup = null;

let softPageRuntimeSequence = 0;

let historyPositionTimer = null;

let historyPositionTrackingSuspended = false;

let pendingNavGliderLink = null;

const softNavigationPrefetches = new Map();

const SOFT_NAVIGATION_PREFETCH_TTL = 30000;

const SOFT_NAVIGATION_PREFETCH_LIMIT = 12;

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
    [...(menu.querySelector('[data-mobile-menu-content]')?.children || [])].forEach((item, index) => {
        item.style.setProperty('--mobile-menu-item-delay', `${Math.min(index, 9) * 38}ms`);
    });
    menu.classList.add('is-open');
    menu.classList.remove('opacity-0', '-translate-y-5', 'pointer-events-none');
    menu.classList.add('opacity-100', 'translate-y-0');
    menu.setAttribute('aria-hidden', 'false');
    menu.removeAttribute('inert');
    button.setAttribute('aria-expanded', 'true');
    setNavButtonLabel(button, 'Close');
}

function closeMobileMenu() {
    const menu = mobileMenu();
    const button = navButton();

    if (!menu || !button) return;

    mobileMenuOpen = false;
    menu.classList.remove('is-open');
    menu.classList.add('opacity-0', '-translate-y-5', 'pointer-events-none');
    menu.classList.remove('opacity-100', 'translate-y-0');
    menu.setAttribute('aria-hidden', 'true');
    menu.setAttribute('inert', '');
    if (menu.contains(document.activeElement)) button.focus();
    button.setAttribute('aria-expanded', 'false');
    setNavButtonLabel(button, 'Menu');
}

function toggleProfileDropdown() {
    const dropdown = document.getElementById('dropdown');
    const toggle = document.querySelector('[data-profile-toggle]');

    if (!dropdown) return;

    const willOpen = !dropdown.classList.contains('is-open');
    dropdown.classList.toggle('is-open', willOpen);
    dropdown.setAttribute('aria-hidden', willOpen ? 'false' : 'true');
    dropdown.toggleAttribute('inert', !willOpen);
    toggle?.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
}

function closeProfileDropdown() {
    const dropdown = document.getElementById('dropdown');

    dropdown?.classList.remove('is-open');
    dropdown?.setAttribute('aria-hidden', 'true');
    dropdown?.setAttribute('inert', '');
    document.querySelector('[data-profile-toggle]')?.setAttribute('aria-expanded', 'false');
}

function updateNavbarOnScroll() {
    const navbar = document.getElementById('navbar');

    if (!navbar) return;

    const currentScroll = window.pageYOffset;

    if (mobileMenuOpen || miniCartRoot()?.classList.contains('is-open') || miniCartRoot()?.classList.contains('is-auto-open')) {
        navbar.classList.remove('nav-hidden');
        lastNavbarScroll = currentScroll;
        return;
    }

    const shouldHideNavbar = currentScroll > lastNavbarScroll && currentScroll > 50;

    if (shouldHideNavbar) closeProfileDropdown();

    navbar.classList.toggle('nav-hidden', shouldHideNavbar);
    lastNavbarScroll = currentScroll;
}

function pageContentShell() {
    return document.querySelector('[data-aksa-page-content]');
}

function updateNavGlider(previewLink = null) {
    const menu = document.getElementById('navMenu');
    const glider = menu?.querySelector('[data-nav-glider]');
    const preferredLink = previewLink || pendingNavGliderLink;
    const activeLink = preferredLink?.closest('#navMenu') === menu
        ? preferredLink
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

function setPendingNavGlider(link) {
    if (!link?.closest('#navMenu')) return;

    pendingNavGliderLink?.classList.remove('is-navigation-pending');
    pendingNavGliderLink = link;
    pendingNavGliderLink.classList.add('is-navigation-pending');
    updateNavGlider(pendingNavGliderLink);
}

function clearPendingNavGlider(link = null) {
    if (link && pendingNavGliderLink !== link) return;

    pendingNavGliderLink?.classList.remove('is-navigation-pending');
    pendingNavGliderLink = null;
    updateNavGlider();
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

function persistCurrentHistoryPosition() {
    const position = {
        x: Math.max(0, Math.round(window.scrollX)),
        y: Math.max(0, Math.round(window.scrollY)),
    };

    window.history.replaceState({
        ...(window.history.state || {}),
        aksaSoftNavigation: true,
        aksaScrollPosition: position,
    }, '', window.location.href);
}

function cancelScheduledHistoryPosition() {
    window.clearTimeout(historyPositionTimer);
    historyPositionTimer = null;
}

function scheduleCurrentHistoryPosition() {
    if (historyPositionTrackingSuspended) return;

    window.clearTimeout(historyPositionTimer);
    historyPositionTimer = window.setTimeout(() => {
        historyPositionTimer = null;
        persistCurrentHistoryPosition();
    }, 120);
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

function executeSoftPageScripts(scripts) {
    cleanupSoftPageScripts();

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

function scrollAfterSoftNavigation(url, historyState = null) {
    const savedPosition = historyState?.aksaScrollPosition;

    if (savedPosition && Number.isFinite(savedPosition.x) && Number.isFinite(savedPosition.y)) {
        window.scrollTo({
            left: Math.max(0, savedPosition.x),
            top: Math.max(0, savedPosition.y),
            behavior: 'auto',
        });
        return;
    }

    if (url.hash) {
        document.getElementById(decodeURIComponent(url.hash.slice(1)))?.scrollIntoView({ block: 'start' });
        return;
    }

    window.scrollTo({ top: 0, behavior: 'auto' });
}

async function requestSoftNavigationPage(url, signal = null) {
    const dashboardFilter = window.location.pathname === '/admin' && url.pathname === '/admin';
    const response = await fetch(url.href, {
        credentials: 'same-origin',
        headers: {
            Accept: 'text/html',
            'X-Requested-With': 'XMLHttpRequest',
            ...(dashboardFilter ? { 'X-Aksa-Dashboard-Fragment': '1' } : {}),
        },
        ...(signal ? { signal } : {}),
    });
    const contentType = response.headers.get('content-type') || '';

    if (!response.ok || !contentType.includes('text/html')) {
        throw new Error(`Soft navigation failed (${response.status})`);
    }

    if (response.redirected && new URL(response.url).origin !== window.location.origin) {
        throw new Error('Soft navigation was redirected outside this site');
    }

    return {
        html: await response.text(),
        responseUrl: response.url,
    };
}

function trimSoftNavigationPrefetches() {
    while (softNavigationPrefetches.size > SOFT_NAVIGATION_PREFETCH_LIMIT) {
        softNavigationPrefetches.delete(softNavigationPrefetches.keys().next().value);
    }
}

function prefetchSoftNavigationPage(url) {
    const existing = softNavigationPrefetches.get(url.href);

    if (existing && existing.expiresAt > Date.now()) return existing.promise;

    if (existing) softNavigationPrefetches.delete(url.href);

    const entry = {
        expiresAt: Date.now() + SOFT_NAVIGATION_PREFETCH_TTL,
        promise: null,
    };

    entry.promise = requestSoftNavigationPage(url).catch((error) => {
        if (softNavigationPrefetches.get(url.href) === entry) {
            softNavigationPrefetches.delete(url.href);
        }

        throw error;
    });
    entry.promise.catch(() => {});
    softNavigationPrefetches.set(url.href, entry);
    trimSoftNavigationPrefetches();

    return entry.promise;
}

function abortableSoftNavigationPrefetch(promise, signal) {
    if (signal.aborted) {
        return Promise.reject(new DOMException('Navigation aborted', 'AbortError'));
    }

    return new Promise((resolve, reject) => {
        const abort = () => reject(new DOMException('Navigation aborted', 'AbortError'));
        signal.addEventListener('abort', abort, { once: true });

        promise.then(
            (value) => {
                signal.removeEventListener('abort', abort);
                resolve(value);
            },
            (error) => {
                signal.removeEventListener('abort', abort);
                reject(error);
            },
        );
    });
}

function takePrefetchedSoftNavigationPage(url, signal) {
    const entry = softNavigationPrefetches.get(url.href);

    if (!entry || entry.expiresAt <= Date.now()) {
        if (entry) softNavigationPrefetches.delete(url.href);
        return null;
    }

    softNavigationPrefetches.delete(url.href);

    return abortableSoftNavigationPrefetch(entry.promise, signal);
}

function softNavigationLinkForPrefetch(event) {
    return event.target instanceof Element
        ? event.target.closest('a[data-soft-nav]')
        : null;
}

function prefetchSoftNavigationLink(link) {
    if (!link || link.dataset.noSoftNav !== undefined) return;

    const rawHref = link.getAttribute('href');
    if (!rawHref) return;

    const url = new URL(rawHref, window.location.href);

    if (url.origin !== window.location.origin || url.href === window.location.href) return;

    prefetchSoftNavigationPage(url);
}

function attachRestoredPageState(content, historyState) {
    const homeView = historyState?.aksaHomeView;
    const scrollPosition = historyState?.aksaScrollPosition;

    if (typeof homeView?.search === 'string') {
        content.dataset.aksaRestoredHomeSearch = homeView.search;
    }

    if (typeof homeView?.category === 'string') {
        content.dataset.aksaRestoredHomeCategory = homeView.category;
    }

    if (Number.isFinite(scrollPosition?.y)) {
        content.dataset.aksaRestoredScrollY = String(scrollPosition.y);

        if (homeView && content.matches('[data-aksa-home-content]')) {
            content.dataset.aksaScrollRestorePending = 'true';
        }
    }
}

async function softNavigate(url, options = {}) {
    const nextUrl = new URL(url, window.location.href);
    const currentContent = pageContentShell();
    const previousUrl = window.location.href;
    const restoringHistory = options.pushHistory === false;
    let deferredHistoryPositionRestore = false;

    if (!currentContent) {
        window.location.href = nextUrl.href;
        return;
    }

    activeSoftNavigation?.abort();

    const controller = new AbortController();
    activeSoftNavigation = controller;
    const pendingLink = options.pendingLink || null;
    const navigationPreviewLink = options.navigationPreviewLink || null;

    cancelScheduledHistoryPosition();
    if (!restoringHistory) persistCurrentHistoryPosition();
    historyPositionTrackingSuspended = true;
    window.dispatchEvent(new CustomEvent('aksa:before-page-swap'));
    document.dispatchEvent(new CustomEvent('aksa:before-page-swap'));
    currentContent.classList.remove('aksa-soft-nav-entered');
    document.body.classList.add('aksa-soft-nav-active');
    currentContent.setAttribute('aria-busy', 'true');
    pendingLink?.classList.add('is-soft-nav-pending');

    try {
        const prefetchedPage = takePrefetchedSoftNavigationPage(nextUrl, controller.signal);
        const { html } = await (prefetchedPage || requestSoftNavigationPage(nextUrl, controller.signal));
        const nextDocument = new DOMParser().parseFromString(html, 'text/html');
        const nextContent = nextDocument.querySelector('[data-aksa-page-content]');
        const nextPageScripts = [...nextDocument.body.querySelectorAll('script')].filter(runnableScript);

        if (!nextContent) {
            window.location.href = nextUrl.href;
            return;
        }

        if (options.pushHistory === false) {
            attachRestoredPageState(nextContent, window.history.state);
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

            window.history.pushState({
                aksaSoftNavigation: true,
                aksaPreviousUrl: previousUrl,
            }, '', nextUrl.href);
        }

        if (restoringHistory && nextContent.dataset.aksaScrollRestorePending === 'true') {
            deferredHistoryPositionRestore = true;
            let restoreFallbackTimer = null;

            const finishHistoryPositionRestore = () => {
                window.clearTimeout(restoreFallbackTimer);
                nextContent.removeEventListener('aksa:history-scroll-restored', finishHistoryPositionRestore);
                nextContent.removeAttribute('data-aksa-scroll-restore-pending');

                window.requestAnimationFrame(() => {
                    if (pageContentShell() !== nextContent || (activeSoftNavigation && activeSoftNavigation !== controller)) return;

                    historyPositionTrackingSuspended = false;
                    persistCurrentHistoryPosition();
                });
            };

            nextContent.addEventListener('aksa:history-scroll-restored', finishHistoryPositionRestore, { once: true });
            restoreFallbackTimer = window.setTimeout(finishHistoryPositionRestore, 5000);
        }

        executeSoftPageScripts(nextPageScripts);
        initializeCheckout(nextContent);
        initializeDisplayCurrency(document);
        initializeCustomSelects(nextContent);
        initializeRecentPurchaseToast(nextContent);
        initializeDownloadAccordions(nextContent);
        initializeMotionEnhancements(nextContent);
        beginHomeStagedReveal(nextContent);
        closeMobileMenu();
        closeProfileDropdown();
        scrollAfterSoftNavigation(
            nextUrl,
            options.pushHistory === false ? window.history.state : null,
        );

        requestAnimationFrame(() => {
            clearPendingNavGlider(navigationPreviewLink);
            updateNavGlider();
            nextContent.classList.add('aksa-soft-nav-entered');
            window.setTimeout(() => nextContent.classList.remove('aksa-soft-nav-entered'), 540);
        });
    } catch (error) {
        if (error.name === 'AbortError') return;

        window.location.href = nextUrl.href;
    } finally {
        const finishedCurrentNavigation = activeSoftNavigation === controller;

        if (finishedCurrentNavigation) {
            activeSoftNavigation = null;
            document.body.classList.remove('aksa-soft-nav-active');
            pageContentShell()?.removeAttribute('aria-busy');
            clearPendingNavGlider(navigationPreviewLink);
        }

        pendingLink?.classList.remove('is-soft-nav-pending');

        if (finishedCurrentNavigation && !deferredHistoryPositionRestore) {
            requestAnimationFrame(() => {
                if (activeSoftNavigation) return;

                historyPositionTrackingSuspended = false;
                persistCurrentHistoryPosition();
            });
        }
    }
}

export { mobileMenuOpen, ENABLE_AKSA_SAFE_SOFT_NAVIGATION, navButton, mobileMenu, openMobileMenu, closeMobileMenu, toggleProfileDropdown, closeProfileDropdown, updateNavbarOnScroll, updateNavGlider, setPendingNavGlider, navItemFromEvent, shouldSoftNavigateLink, shouldSoftNavigateForm, formNavigationUrl, persistCurrentHistoryPosition, scheduleCurrentHistoryPosition, softNavigationLinkForPrefetch, prefetchSoftNavigationLink, softNavigate };
