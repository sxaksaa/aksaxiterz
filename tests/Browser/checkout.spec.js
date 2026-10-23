import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

async function openCheckout(page, testInfo) {
    const html = readFileSync(join(testInfo.config.metadata.browserSandbox, 'checkout.html'), 'utf8');
    await page.route('**/checkout-review-fixture', r => r.fulfill({ contentType: 'text/html', body: html }));
    await page.goto('/checkout-review-fixture');
    await page.waitForFunction(() => typeof window.aksaFetchWithCsrf === 'function');
    await page.locator('input[name="payment_method"][value="gopay_qris"]').evaluate(el => el.click());
    await expect(page.locator('#checkoutSubmitButton')).toBeEnabled();
    await expect(page.locator('#checkoutTotal')).toContainText('platform fee + unique amount');
}

test('pending invoice keeps its loading state and selection until an error allows retry', async ({ page }, testInfo) => {
    await openCheckout(page, testInfo);
    let release;
    const gate = new Promise(resolve => { release = resolve; });
    let requests = 0;
    let payload;
    await page.route('**/checkout/checkout-fixture', async r => {
        requests++;
        payload = r.request().postData();
        await gate;
        await r.fulfill({ status: 422, json: { message: 'Please try again.' } });
    });
    await page.locator('#checkoutSubmitButton').click();
    await expect.poll(() => requests).toBe(1);
    await page.evaluate(() => window.dispatchEvent(new Event('aksa:currency-change')));
    await expect(page.locator('#checkoutSubmitButton')).toBeDisabled();
    await expect(page.locator('#checkoutSubmitButton')).toContainText('Creating Invoice...');
    await expect(page.locator('input[name="payment_method"][value="gopay_qris"]')).toBeDisabled();
    await expect(page.locator('#checkoutVoucherCode')).toBeDisabled();
    expect(payload).toContain('gopay_qris');
    expect(payload).toContain('review_signature');
    await page.locator('#checkoutForm').evaluate(form => form.dispatchEvent(new Event('submit', { cancelable: true })));
    expect(requests).toBe(1);
    release();
    await expect(page.locator('#checkoutSubmitButton')).toBeEnabled();
    await expect(page.locator('#checkoutSubmitButton')).toContainText('Create Invoice');
    await expect(page.locator('#checkoutVoucherCode')).toBeEnabled();
    await expect(page.locator('input[name="payment_method"][value="gopay_qris"]')).toBeEnabled();
});

test('voucher refreshes expired CSRF and retries with the new token', async ({ page }, testInfo) => {
    await openCheckout(page, testInfo);
    let previews = 0;
    let refreshes = 0;
    await page.route('**/csrf-token', r => {
        refreshes++;
        return r.fulfill({ json: { token: 'renewed-checkout-token' } });
    });
    await page.route('**/voucher/preview', r => {
        previews++;
        if (previews === 1) return r.fulfill({ status: 419, json: { message: 'CSRF token mismatch.' } });
        expect(r.request().headers()['x-csrf-token']).toBe('renewed-checkout-token');
        return r.fulfill({ json: { code: 'TEST', discount_percent: 10, discount_idr: 2000, final_idr: 18000 } });
    });
    await page.locator('#checkoutVoucherCode').fill('TEST');
    await page.locator('#checkoutApplyVoucher').click();
    await expect(page.locator('#checkoutVoucherFeedback')).toContainText('10% voucher applied');
    expect(previews).toBe(2);
    expect(refreshes).toBe(1);
    await expect(page.locator('#checkoutTotal')).toContainText('18.000');
    await expect(page.locator('#checkoutSubmitButton')).toBeEnabled();
});

test('changed review opens the refreshed checkout without creating another invoice', async ({ page }, testInfo) => {
    await openCheckout(page, testInfo);
    await page.route('**/checkout/checkout-fixture', r => r.fulfill({ status: 409, json: {
        message: 'The product or price changed. Review the updated checkout before paying.',
        review_url: '/updated-review',
    } }));
    await page.route('**/updated-review', r => r.fulfill({ contentType: 'text/html', body: '<h1>Updated checkout review</h1>' }));
    await page.locator('#checkoutSubmitButton').click();
    await expect(page).toHaveURL(/updated-review$/);
    await expect(page.locator('h1')).toHaveText('Updated checkout review');
});

test('checkout initializes after soft navigation and re-entry without duplicate voucher requests', async ({ page }, testInfo) => {
    const html = readFileSync(join(testInfo.config.metadata.browserSandbox, 'checkout.html'), 'utf8');
    await page.route('**/checkout-review-fixture', r => r.fulfill({ contentType: 'text/html', body: html }));
    await page.goto('/guides');
    await page.waitForFunction(() => typeof window.aksaFetchWithCsrf === 'function');
    await page.evaluate(() => { window.checkoutNavigationMarker = 'preserved'; });
    const enterCheckout = async () => {
        await page.evaluate(() => {
            const link = document.createElement('a');
            link.href = '/checkout-review-fixture';
            link.dataset.softNav = '';
            link.id = 'fixtureCheckoutLink';
            link.textContent = 'Review checkout';
            document.querySelector('main').append(link);
        });
        await page.locator('#fixtureCheckoutLink').click();
        await expect(page.locator('#checkoutForm')).toHaveAttribute('data-checkout-ready', 'true');
    };
    await enterCheckout();
    await page.locator('#checkoutForm').evaluate(form => {
        const link = document.createElement('a');
        link.href = '/guides';
        link.dataset.softNav = '';
        link.id = 'fixtureLeaveCheckout';
        link.textContent = 'Leave checkout';
        form.append(link);
    });
    await page.locator('#fixtureLeaveCheckout').click();
    await expect(page).toHaveURL(/\/guides$/);
    await expect(page.locator('#checkoutForm')).toHaveCount(0);
    await enterCheckout();
    expect(await page.evaluate(() => window.checkoutNavigationMarker)).toBe('preserved');
    let requests = 0;
    await page.route('**/voucher/preview', r => {
        requests++;
        return r.fulfill({ status: 422, json: { message: 'Test voucher is unavailable.' } });
    });
    await page.locator('input[name="payment_method"][value="gopay_qris"]').evaluate(el => el.click());
    await page.locator('#checkoutVoucherCode').fill('TEST');
    await page.locator('#checkoutApplyVoucher').click();
    await expect(page.locator('#checkoutVoucherFeedback')).toContainText('Test voucher is unavailable.');
    expect(requests).toBe(1);
});
