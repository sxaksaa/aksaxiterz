import { setButtonLabel } from './ui.js';
import { initializeDisplayCurrency } from './currency.js';
import { shouldReduceMotion } from './motion-preferences.js';

const MINI_CART_SHEET_BREAKPOINT = 768;

const MINI_CART_SHEET_TRANSITION_DURATION = 420;

let miniCartReturnTimer = null;

function usesMiniCartSheet() {
    return window.innerWidth < MINI_CART_SHEET_BREAKPOINT;
}

function shouldLockPageForMiniCart() {
    const hasPreciseHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;

    return usesMiniCartSheet() && !hasPreciseHover;
}

let miniCartAutoCloseTimer = null;

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
    } else if (!usesMiniCartSheet() && panel.parentElement !== root) {
        root.append(backdrop, panel);
    }
}

function setMiniCartOpen(open, { auto = false } = {}) {
    const root = miniCartRoot();
    const trigger = root?.querySelector('[data-mini-cart-trigger]');

    if (!root || !trigger) return;

    if (open) document.getElementById('navbar')?.classList.remove('nav-hidden');

    window.clearTimeout(miniCartReturnTimer);
    moveMiniCartOverlay(root, open);
    if (open && usesMiniCartSheet()) {
        // Establish the sheet's hidden position after moving it to body.
        void miniCartOverlayParts().panel?.offsetHeight;
    }

    clearTimeout(miniCartAutoCloseTimer);
    root.classList.toggle('is-open', open);
    root.classList.toggle('is-auto-open', open && auto);
    const { panel, backdrop } = miniCartOverlayParts();
    panel?.classList.toggle('is-visible', open);
    backdrop?.classList.toggle('is-visible', open);
    trigger.setAttribute('aria-expanded', String(open));
    if (open) {
        document.body.classList.toggle('mini-cart-sheet-open', shouldLockPageForMiniCart());
    } else if (panel?.parentElement === document.body && usesMiniCartSheet()) {
        miniCartReturnTimer = window.setTimeout(() => {
            if (!root.classList.contains('is-open')) root.append(backdrop, panel);
            document.body.classList.remove('mini-cart-sheet-open');
        }, shouldReduceMotion() ? 0 : MINI_CART_SHEET_TRANSITION_DURATION);
    } else {
        document.body.classList.remove('mini-cart-sheet-open');
    }

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

let pendingCartRestores = 0;

function updateCartRemovalSummary(distinctDelta, quantityDelta, idrDelta, usdDelta) {
    const bundleCount = document.getElementById('cartBundleCount');
    const subtotal = document.querySelector('[data-cart-subtotal]');

    if (bundleCount) {
        const distinctItems = Math.max(0, Number(bundleCount.dataset.cartDistinctItems || 0) + distinctDelta);
        const totalQuantity = Math.max(0, Number(bundleCount.dataset.cartTotalQuantity || 0) + quantityDelta);

        bundleCount.dataset.cartDistinctItems = String(distinctItems);
        bundleCount.dataset.cartTotalQuantity = String(totalQuantity);
        window.animateAksaValue?.(
            bundleCount,
            `${distinctItems} ${distinctItems === 1 ? 'package' : 'packages'} · ${totalQuantity} ${totalQuantity === 1 ? 'license' : 'licenses'}`
        );

        document.querySelectorAll('[data-cart-count]').forEach(badge => {
            window.animateAksaValue?.(badge, String(totalQuantity));
            badge.classList.toggle('hidden', totalQuantity <= 0);
        });

        const miniCart = miniCartRoot();
        if (miniCart) {
            miniCart.dataset.miniCartLoaded = 'false';
            miniCart.querySelector('[data-mini-cart-trigger]')?.setAttribute(
                'aria-label',
                `Open cart with ${totalQuantity} items`
            );
        }
    }

    if (subtotal) {
        const previousSubtotal = subtotal.textContent?.trim() || '';
        const nextIdr = Math.max(0, Number(subtotal.dataset.priceIdr || 0) + idrDelta);
        const currentUsd = Number(subtotal.dataset.priceUsd || 0);
        const nextUsd = Math.max(0, currentUsd + usdDelta);

        subtotal.dataset.priceIdr = String(nextIdr);
        if (subtotal.dataset.priceUsd !== '') subtotal.dataset.priceUsd = String(nextUsd);
        window.refreshAksaDisplayCurrency?.(subtotal.parentElement || document);
        window.animateAksaValue?.(subtotal, subtotal.textContent?.trim() || '', {
            previousText: previousSubtotal,
        });
    }
}

export { usesMiniCartSheet, miniCartRoot, setMiniCartOpen, closeMiniCart, ensureMiniCartLoaded };

export function registerCart() {
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
            window.setTimeout(() => nextContent.classList.remove('mini-cart-content-enter'), 560);

            const highlightItemId = Number(options.highlightItemId || 0);
            const highlightedItem = highlightItemId > 0
                ? nextContent.querySelector(`[data-mini-cart-item-id="${highlightItemId}"]`)
                : null;

            if (highlightedItem) {
                window.requestAnimationFrame(() => {
                    highlightedItem.classList.add('is-cart-highlighted');
                    window.setTimeout(() => highlightedItem.classList.remove('is-cart-highlighted'), 1350);
                });
            }
        }

        root.querySelector('[data-mini-cart-trigger]')?.setAttribute(
            'aria-label',
            `Open cart with ${Number(cartCount || 0)} items`
        );
        document.querySelectorAll('[data-cart-count]').forEach((badge) => {
            const nextCount = String(Number(cartCount || 0));
            window.animateAksaValue?.(badge, nextCount);
            badge.classList.toggle('hidden', Number(cartCount || 0) < 1);
        });
        initializeDisplayCurrency(panel);

        if (options.bumpBadge) {
            root.classList.remove('mini-cart-bump');
            void root.offsetWidth;
            root.classList.add('mini-cart-bump');
            window.setTimeout(() => root.classList.remove('mini-cart-bump'), 620);
        }

        if (options.firstItem) {
            root.classList.remove('mini-cart-first-item');
            void root.offsetWidth;
            root.classList.add('mini-cart-first-item');
            window.setTimeout(() => root.classList.remove('mini-cart-first-item'), 650);
        }

        if (options.autoOpen) setMiniCartOpen(true, { auto: true });
    };

    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-cart-remove-form]');
        let row = form?.closest('[data-cart-item]');

        if (!form || !row || event.defaultPrevented || form.dataset.cartRemovalPending === 'true') return;

        event.preventDefault();
        if (row.querySelector('[data-cart-quantity-form]')?.dataset.cartUpdating === 'true') return;
        form.dataset.cartRemovalPending = 'true';

        let quantity = Math.max(1, Number(row.dataset.cartItemQuantity || 1));
        const lineTotal = row.querySelector('[data-cart-line-total]');
        let lineTotalIdr = Math.max(0, Number(lineTotal?.dataset.priceIdr || 0));
        let lineTotalUsd = Math.max(0, Number(lineTotal?.dataset.priceUsd || 0));
        let packageName = row.querySelector('.font-semibold.text-white')?.textContent?.trim() || 'Package';
        const action = form.action;
        const restoreUrl = row.dataset.cartRestoreUrl || '';
        const packageId = Number(row.dataset.cartPackageId || 0);
        const requestBody = new FormData(form);
        const group = row.closest('[data-cart-product-group]');
        const originalGroupCount = group?.querySelectorAll('[data-cart-item]').length || 1;
        const updateGroupCount = count => {
            const label = group?.querySelector('[data-cart-group-count]');
            if (label) label.textContent = `${count} ${count === 1 ? 'package' : 'packages'} selected`;
        };

        row.classList.add('cart-item-removing');
        row.setAttribute('aria-hidden', 'true');
        row.setAttribute('inert', '');
        updateCartRemovalSummary(-1, -quantity, -lineTotalIdr, -lineTotalUsd);
        updateGroupCount(Math.max(0, originalGroupCount - 1));

        const revealRestoredRow = () => {
            window.clearTimeout(form._cartRemovalCleanupTimer);
            form.dataset.cartRemovalPending = 'false';
            delete row.dataset.cartRemovalCommitted;
            row.classList.remove('cart-item-removing');
            row.removeAttribute('aria-hidden');
            row.removeAttribute('inert');
            updateCartRemovalSummary(1, quantity, lineTotalIdr, lineTotalUsd);
            const subtotal = document.querySelector('[data-cart-subtotal]');
            const activeTotals = [...document.querySelectorAll('[data-cart-item]:not(.cart-item-removing) [data-cart-line-total]')];
            if (subtotal && activeTotals.length > 0) {
                subtotal.dataset.priceUsd = activeTotals.every(total => total.dataset.priceUsd !== '')
                    ? String(activeTotals.reduce((sum, total) => sum + Number(total.dataset.priceUsd || 0), 0))
                    : '';
                window.refreshAksaDisplayCurrency?.(subtotal.parentElement || document);
            }
            updateGroupCount(group?.querySelectorAll('[data-cart-item]:not(.cart-item-removing)').length || 1);
            row.classList.add('cart-item-restored');
            window.setTimeout(() => row.classList.remove('cart-item-restored'), 1400);
        };

        const removeCommittedRow = () => {
            row.remove();

            const remainingInGroup = group?.querySelectorAll('[data-cart-item]').length || 0;
            if (group && remainingInGroup === 0) {
                group.remove();
            } else if (group) {
                const count = group.querySelector('[data-cart-group-count]');
                if (count) count.textContent = `${remainingInGroup} ${remainingInGroup === 1 ? 'package' : 'packages'} selected`;
            }

            const bundleCount = document.getElementById('cartBundleCount');
            if (
                Number(bundleCount?.dataset.cartDistinctItems || 0) === 0 &&
                pendingCartRestores === 0 &&
                window.location.pathname === '/cart'
            ) {
                window.location.reload();
            }
        };

        try {
            const response = await window.aksaFetchWithCsrf(action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: requestBody,
            });
            const data = await response.json().catch(() => ({}));

            if (!response.ok) throw new Error(data.message || 'The package could not be removed.');

            row.dataset.cartRemovalCommitted = 'true';
            const restoreRow = async () => {
                if (!restoreUrl || packageId <= 0 || row.dataset.cartRestoring === 'true') return;

                row.dataset.cartRestoring = 'true';
                pendingCartRestores++;
                window.clearTimeout(form._cartRemovalCleanupTimer);
                try {
                    const restoreBody = new FormData();
                    restoreBody.set('package_id', String(packageId));
                    restoreBody.set('quantity', String(quantity));
                    const restoreResponse = await window.aksaFetchWithCsrf(restoreUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: restoreBody,
                    });
                    const restoreData = await restoreResponse.json().catch(() => ({}));

                    if (!restoreResponse.ok) throw new Error(restoreData.message || 'The package could not be restored.');

                    const template = document.createElement('template');
                    template.innerHTML = restoreData.cart_item_html || '';
                    const freshRow = template.content.querySelector('[data-cart-item]');
                    if (freshRow) {
                        // Reuse the server-rendered row so price, duration, quantity and stock controls agree.
                        freshRow.classList.add('cart-item-removing');
                        row.replaceWith(freshRow);
                        row = freshRow;
                        quantity = Math.max(1, Number(row.dataset.cartItemQuantity || 1));
                        const freshTotal = row.querySelector('[data-cart-line-total]');
                        lineTotalIdr = Math.max(0, Number(freshTotal?.dataset.priceIdr || 0));
                        lineTotalUsd = Math.max(0, Number(freshTotal?.dataset.priceUsd || 0));
                        packageName = row.querySelector('.font-semibold.text-white')?.textContent?.trim() || 'Package';
                        window.refreshAksaDisplayCurrency?.(row);
                    }

                    const restoredItemId = Number(restoreData.item_id || 0);
                    if (restoredItemId > 0) {
                        row.dataset.cartItem = String(restoredItemId);
                        row.querySelectorAll('[data-cart-quantity-form], [data-cart-remove-form]').forEach(itemForm => {
                            const itemUrl = new URL(itemForm.action, window.location.origin);
                            itemUrl.pathname = itemUrl.pathname.replace(/\/[^/]+$/, `/${restoredItemId}`);
                            itemForm.action = itemUrl.toString();
                        });
                    }

                    revealRestoredRow();
                    window.showAppToast?.('Package restored', `${packageName} is back in your cart.`, {
                        variant: 'success',
                    });
                } catch (error) {
                    form._cartRemovalCleanupTimer = window.setTimeout(removeCommittedRow, 5100);
                    window.showAppToast?.('Undo failed', error.message || 'Add the package again from its product page.', {
                        variant: 'error',
                    });
                } finally {
                    pendingCartRestores--;
                    row.dataset.cartRestoring = 'false';
                }
            };

            window.showAppToast?.('Package removed', `${packageName} was removed from your cart.`, {
                variant: 'success',
                duration: 5000,
                actionLabel: 'Undo',
                onAction: () => { void restoreRow(); },
            });
            form._cartRemovalCleanupTimer = window.setTimeout(removeCommittedRow, 5100);
        } catch (error) {
            revealRestoredRow();
            window.showAppToast?.('Cart not updated', error.message || 'Refresh the page and try again.', {
                variant: 'error',
            });
        }
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
        const removeButtons = [...row.querySelectorAll('[data-cart-remove-form] button')];
        const removeDisabled = removeButtons.map(button => button.disabled);
        removeButtons.forEach(button => { button.disabled = true; });
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
            row.dataset.cartItemQuantity = String(quantity);
            const maxQuantity = Number(item.max_quantity || quantity);
            const quantityOutput = form.querySelector('.quantity-stepper-value');
            window.animateAksaValue?.(quantityOutput, String(quantity));

            const lineTotal = row.querySelector('[data-cart-line-total]');
            if (lineTotal) {
                const previousLineTotal = lineTotal.textContent?.trim() || '';
                lineTotal.dataset.priceIdr = String(item.line_total_idr ?? 0);
                lineTotal.dataset.priceUsd = String(item.line_total_usdt ?? '');
                window.refreshAksaDisplayCurrency?.(row);
                window.animateAksaValue?.(lineTotal, lineTotal.textContent?.trim() || '', {
                    previousText: previousLineTotal,
                });
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
            if (bundleCount) {
                bundleCount.dataset.cartDistinctItems = String(distinctItems);
                bundleCount.dataset.cartTotalQuantity = String(totalQuantity);
            }
            window.animateAksaValue?.(
                bundleCount,
                `${distinctItems} ${distinctItems === 1 ? 'package' : 'packages'} · ${totalQuantity} ${totalQuantity === 1 ? 'license' : 'licenses'}`
            );

            const subtotal = document.querySelector('[data-cart-subtotal]');
            if (subtotal) {
                const previousSubtotal = subtotal.textContent?.trim() || '';
                subtotal.dataset.priceIdr = String(cart.subtotal_idr ?? 0);
                subtotal.dataset.priceUsd = String(cart.subtotal_usdt ?? '');
                window.refreshAksaDisplayCurrency?.(subtotal.parentElement || document);
                window.animateAksaValue?.(subtotal, subtotal.textContent?.trim() || '', {
                    previousText: previousSubtotal,
                });
            }

            document.querySelectorAll('[data-cart-count]').forEach(badge => {
                window.animateAksaValue?.(badge, String(totalQuantity));
                badge.classList.toggle('hidden', totalQuantity <= 0);
            });

            const miniCart = miniCartRoot();
            if (miniCart) {
                miniCart.dataset.miniCartLoaded = 'false';
                miniCart.querySelector('[data-mini-cart-trigger]')?.setAttribute(
                    'aria-label',
                    `Open cart with ${totalQuantity} items`
                );
            }
        } catch (error) {
            previousDisabled.forEach((disabled, index) => { buttons[index].disabled = disabled; });
            window.showAppToast?.('Cart not updated', error.message || 'Refresh the page and try again.', {
                variant: 'error',
            });
        } finally {
            removeButtons.forEach((button, index) => { button.disabled = removeDisabled[index]; });
            form.dataset.cartUpdating = 'false';
            row.classList.remove('cart-item-updating');
        }
    }, true);

    document.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-license-reset-form]');

        if (!form || event.defaultPrevented) return;

        const button = form.querySelector('button[type="submit"]');

        if (!button) return;

        button.disabled = true;
        setButtonLabel(button, 'Resetting...');
        button.classList.add('opacity-60', 'pointer-events-none');
    });
}
