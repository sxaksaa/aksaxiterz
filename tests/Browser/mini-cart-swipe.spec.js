import { expect, test } from '@playwright/test';

test('mobile cart supports dismiss, snap back, cancellation and scrolling', async ({ page, context, isMobile }) => {
    test.skip(!isMobile, 'Bottom sheet gestures are mobile only');
    await page.addInitScript(() => sessionStorage.setItem('aksa_site_intro_seen_v1', 'true'));
    await page.goto('/');
    await page.evaluate(() => {
        const root = document.createElement('div');
        root.dataset.miniCartRoot = '';
        root.dataset.miniCartLoaded = 'true';
        root.innerHTML = `<a href="#" data-mini-cart-trigger aria-expanded="false">Cart</a>
            <button class="mini-cart-backdrop" data-mini-cart-close></button>
            <div class="mini-cart-panel" data-mini-cart-panel>
                <div class="mini-cart-mobile-handle"></div>
                <div data-mini-cart-content></div>
            </div>`;
        document.body.append(root);
        root.querySelector('[data-mini-cart-panel]').style.height = '360px';
        const items = '<p>Cart item</p>'.repeat(40);
        window.refreshAksaMiniCart(`<div data-mini-cart-content><div class="mini-cart-header">Your cart</div>${items}<button>Checkout</button></div>`, 1, { autoOpen: true });
    });
    const panel = page.locator('[data-mini-cart-panel]');
    const trigger = page.locator('[data-mini-cart-trigger]');
    await expect(panel).toBeVisible();
    await expect.poll(() => panel.evaluate(el => Math.round(el.getBoundingClientRect().bottom))).toBe(page.viewportSize().height);
    const cdp = await context.newCDPSession(page);
    const swipe = async (distance, cancel = false, offset = 35) => {
        await expect.poll(() => panel.evaluate(el => Math.round(el.getBoundingClientRect().bottom))).toBe(page.viewportSize().height);
        const box = await panel.boundingBox();
        const x = box.x + box.width / 2;
        const y = box.y + offset;
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
        for (let i = 1; i <= 6; i++) {
            await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x, y: y + distance * i / 6 }] });
        }
        await cdp.send('Input.dispatchTouchEvent', { type: cancel ? 'touchCancel' : 'touchEnd', touchPoints: [] });
    };
    await swipe(25);
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await expect.poll(() => panel.evaluate(el => el.style.transform)).toBe('');
    await swipe(110, true);
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await expect.poll(() => panel.evaluate(el => el.style.transform)).toBe('');
    await swipe(-100, false, 150);
    await expect.poll(() => panel.evaluate(el => el.scrollTop)).toBeGreaterThan(0);
    await swipe(110);
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await panel.evaluate(el => { el.scrollTop = 0; });
    await swipe(120);
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await expect(panel).not.toHaveClass(/is-visible/);
    await expect(page.locator('body')).not.toHaveClass(/mini-cart-sheet-open/);
});
