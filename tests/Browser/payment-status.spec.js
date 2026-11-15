import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

function fixture(testInfo, state = '') {
    return readFileSync(join(testInfo.config.metadata.browserSandbox, `payment${state ? `-${state}` : ''}.html`), 'utf8');
}

test('expiry replaces the single waiting status and retains sent-payment recovery', async ({ page }, testInfo) => {
    await page.clock.install();
    await page.route('**/payment-status-fixture', route => route.fulfill({ contentType: 'text/html', body: fixture(testInfo) }));
    await page.goto('/payment-status-fixture');
    await expect(page.getByText('Waiting for payment', { exact: true })).toHaveCount(1);
    await expect(page.locator('[data-payment-status-banner] [data-payment-icon]')).toHaveAttribute('data-payment-icon-name', 'shield-check');
    await page.waitForFunction(() => typeof window.syncAksaCryptoOrder === 'function');
    await page.evaluate(() => { window.syncAksaCryptoOrder = async () => ({ status: 'pending' }); });
    await page.clock.fastForward(11 * 60 * 1000);
    await expect(page.locator('[data-payment-status-label]')).toHaveText('Payment window expired');
    await expect(page).toHaveTitle('Payment window expired | Aksa Xiterz');
    await expect(page.getByText('Waiting for payment', { exact: true })).toHaveCount(0);
    await expect(page.locator('[data-payment-credentials]')).toBeHidden();
    await expect(page.locator('[data-cancel-payment-form]')).toBeHidden();
    await expect(page.locator('[data-client-closed-instructions]')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Verify Sent Payment' })).toBeVisible();
    await expect(page.locator('.payment-pending-dot')).toHaveCount(0);
    const liveIcon = page.locator('[data-payment-status-banner] [data-payment-icon]');
    await expect(liveIcon).toHaveAttribute('data-payment-icon-name', 'clock');
    const livePaths = await liveIcon.locator('svg').innerHTML();
    await page.screenshot({ path: testInfo.outputPath('expired-payment.png') });
    await page.route('**/expired-icon-fixture', route => route.fulfill({ contentType: 'text/html', body: fixture(testInfo, 'expired') }));
    await page.goto('/expired-icon-fixture');
    for (const icon of await page.locator('[data-payment-icon]').all()) {
        await expect(icon).toHaveAttribute('data-payment-icon-name', 'clock');
        expect(await icon.locator('svg').innerHTML()).toBe(livePaths);
    }
});

test('server-confirmed cancellation and payment replace waiting after refresh', async ({ page }, testInfo) => {
    let html = fixture(testInfo);
    await page.route('**/payment-status-fixture', route => route.fulfill({ contentType: 'text/html', body: html }));
    for (const [state, label] of [['cancelled', 'Checkout cancelled'], ['expired', 'Payment window expired'], ['paid', 'Payment verified']]) {
        html = fixture(testInfo);
        await page.goto('/payment-status-fixture');
        await page.waitForFunction(() => typeof window.syncAksaCryptoOrder === 'function');
        await page.evaluate(state => { window.syncAksaCryptoOrder = async () => ({ status: state }); }, state);
        html = fixture(testInfo, state);
        const refreshed = page.waitForEvent('load');
        await page.getByRole('button', { name: 'Check Payment', exact: true }).click();
        await refreshed;
        await expect(page.locator('[data-payment-status-label]')).toHaveText(label);
        await expect(page.getByText('Waiting for payment', { exact: true })).toHaveCount(0);
        await expect(page.locator('[data-payment-credentials]')).toHaveCount(0);
        await expect(page.locator('[data-cancel-payment-form]')).toHaveCount(0);
        await expect(page).toHaveTitle(`${label} | Aksa Xiterz`);
        const expected = { cancelled: 'circle-x', expired: 'clock', paid: 'check-circle' }[state];
        for (const icon of await page.locator('[data-payment-icon]').all()) {
            await expect(icon).toHaveAttribute('data-payment-icon-name', expected);
            await expect(icon.locator('svg circle')).toHaveCount(1);
        }
    }
});

test('cancel checkout submits normally and shows the confirmed closed state', async ({ page }, testInfo) => {
    await page.route('**/payment-status-fixture', route => route.fulfill({ contentType: 'text/html', body: fixture(testInfo) }));
    await page.route('**/cancel-order/*', route => route.fulfill({ contentType: 'text/html', body: fixture(testInfo, 'cancelled') }));
    await page.goto('/payment-status-fixture');
    await page.getByRole('button', { name: 'Cancel Checkout' }).click();
    await expect(page.locator('[data-payment-status-label]')).toHaveText('Checkout cancelled');
    await expect(page.getByText('Waiting for payment', { exact: true })).toHaveCount(0);
    await expect(page.locator('[data-cancel-payment-form]')).toHaveCount(0);
});
