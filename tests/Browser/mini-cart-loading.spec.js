import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const content = label => `<div data-mini-cart-content><p>${label}</p><a href="/cart">Edit Cart</a></div>`;

async function openFixture(page, testInfo) {
    const html = readFileSync(join(testInfo.config.metadata.browserSandbox, 'checkout.html'), 'utf8');
    await page.route('**/mini-cart-loading-fixture', route => route.fulfill({ contentType: 'text/html', body: html }));
    await page.goto('/mini-cart-loading-fixture');
}

test('cart preview is ready before first hover or tap and is reused', async ({ page, isMobile }, testInfo) => {
    let requests = 0;
    await page.route('**/cart/preview', route => {
        requests++;
        return route.fulfill({ json: { html: content('Ready before opening'), cart_count: 1 } });
    });
    await openFixture(page, testInfo);
    const root = page.locator('[data-mini-cart-root]');
    await expect(root).toHaveAttribute('data-mini-cart-loaded', 'true');
    const trigger = root.locator('[data-mini-cart-trigger]');
    if (isMobile) await trigger.tap();
    else await trigger.hover();
    const panel = page.locator('[data-mini-cart-panel]');
    await expect(panel).toBeVisible();
    await expect(panel).toContainText('Ready before opening');
    await expect(panel.locator('.mini-cart-loading')).toHaveCount(0);
    await expect(root).toHaveAttribute('data-mini-cart-loading', 'false');
    expect(requests).toBe(1);
});

test('an older background response cannot overwrite a newer cart update', async ({ page }, testInfo) => {
    let release;
    const gate = new Promise(resolve => { release = resolve; });
    await page.route('**/cart/preview', async route => {
        await gate;
        await route.fulfill({ json: { html: content('Outdated cart'), cart_count: 1 } });
    });
    await openFixture(page, testInfo);
    const root = page.locator('[data-mini-cart-root]');
    await expect(root).toHaveAttribute('data-mini-cart-loading', 'true');
    await page.evaluate(html => window.refreshAksaMiniCart(html, 2), content('Updated cart'));
    release();
    await expect(root).toHaveAttribute('data-mini-cart-loading', 'false');
    await expect(page.locator('[data-mini-cart-content]')).toContainText('Updated cart');
    await expect(page.locator('[data-mini-cart-content]')).not.toContainText('Outdated cart');
    await expect(root.locator('[data-cart-count]')).toHaveText('2');
});

test('failed warmup remains retryable on the first interaction', async ({ page, isMobile }, testInfo) => {
    let requests = 0;
    await page.route('**/cart/preview', route => {
        requests++;
        return requests === 1
            ? route.fulfill({ status: 503, json: { message: 'Temporary error' } })
            : route.fulfill({ json: { html: content('Recovered cart'), cart_count: 1 } });
    });
    await openFixture(page, testInfo);
    const root = page.locator('[data-mini-cart-root]');
    await expect(page.locator('.mini-cart-load-error')).toHaveCount(1);
    if (isMobile) await root.locator('[data-mini-cart-trigger]').tap();
    else await root.locator('[data-mini-cart-trigger]').hover();
    await expect(root).toHaveAttribute('data-mini-cart-loaded', 'true');
    await expect(page.locator('[data-mini-cart-content]')).toContainText('Recovered cart');
    expect(requests).toBe(2);
});

test('data saving skips background loading while manual opening still works', async ({ page, isMobile }, testInfo) => {
    await page.addInitScript(() => Object.defineProperty(navigator, 'connection', {
        configurable: true, value: { saveData: true, effectiveType: '4g' },
    }));
    await page.clock.install();
    let requests = 0;
    await page.route('**/cart/preview', route => {
        requests++;
        return route.fulfill({ json: { html: content('Requested manually'), cart_count: 1 } });
    });
    await openFixture(page, testInfo);
    await page.clock.runFor(1800);
    expect(requests).toBe(0);
    const trigger = page.locator('[data-mini-cart-trigger]');
    if (isMobile) await trigger.tap();
    else await trigger.focus();
    await expect(page.locator('[data-mini-cart-root]')).toHaveAttribute('data-mini-cart-loaded', 'true');
    expect(requests).toBe(1);
});
