import { modalFocusableElements, activePaymentModal, closePaymentModal } from './payment-modal.js';
import { usesMiniCartSheet, miniCartRoot, setMiniCartOpen, closeMiniCart, ensureMiniCartLoaded } from './cart.js';
import { clearRecentPurchaseToast } from './notifications.js';
import { closeOtherCustomSelects } from './forms.js';
import { mobileMenuOpen, ENABLE_AKSA_SAFE_SOFT_NAVIGATION, navButton, mobileMenu, openMobileMenu, closeMobileMenu, toggleProfileDropdown, closeProfileDropdown, updateNavbarOnScroll, updateNavGlider, setPendingNavGlider, navItemFromEvent, shouldSoftNavigateLink, shouldSoftNavigateForm, formNavigationUrl, persistCurrentHistoryPosition, scheduleCurrentHistoryPosition, softNavigationLinkForPrefetch, prefetchSoftNavigationLink, softNavigate } from './navigation.js';
import { dashboardChartPointFromEvent, showDashboardChartTooltip, hideDashboardChartTooltip } from './dashboard.js';
import { updateCategoryFilterGlider } from './motion.js';

export function registerEvents() {
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
        updateCategoryFilterGlider();
    }, { passive: true });

    let scrollFrame = null;
    window.addEventListener('scroll', () => {
        if (scrollFrame !== null) return;
        scrollFrame = window.requestAnimationFrame(() => {
            scrollFrame = null;
            updateNavbarOnScroll();
            scheduleCurrentHistoryPosition();
        });
    }, { passive: true });

    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');

        if (!shouldSoftNavigateLink(link, event)) return;

        event.preventDefault();

        const nextUrl = new URL(link.getAttribute('href'), window.location.href);
        const navigationPreviewLink = link.closest('#navMenu .nav-item');

        if (navigationPreviewLink) setPendingNavGlider(navigationPreviewLink);

        if (
            link.dataset.softNavBack !== undefined
            && window.history.state?.aksaPreviousUrl === nextUrl.href
        ) {
            persistCurrentHistoryPosition();
            window.history.back();
            return;
        }

        softNavigate(nextUrl.href, {
            pendingLink: link,
            navigationPreviewLink,
        });
    });

    document.addEventListener('submit', async (event) => {
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
        prefetchSoftNavigationLink(softNavigationLinkForPrefetch(event));

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
        prefetchSoftNavigationLink(softNavigationLinkForPrefetch(event));

        if (event.target.closest('[data-mini-cart-root]')) ensureMiniCartLoaded();

        const navItem = navItemFromEvent(event);

        if (navItem) updateNavGlider(navItem);

        const point = dashboardChartPointFromEvent(event);

        if (point) {
            showDashboardChartTooltip(point);
        }
    });

    document.addEventListener('pointerdown', (event) => {
        prefetchSoftNavigationLink(softNavigationLinkForPrefetch(event));
    }, { passive: true });

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
}
