import { expect, test } from '@playwright/test';

test('public storefront has working navigation and SEO metadata', async ({ page }) => {
    const consoleErrors = [];
    page.on('console', (message) => {
        if (message.type() === 'error') consoleErrors.push(message.text());
    });

    await page.goto('/');

    await expect(page).toHaveTitle(/Aksa Xiterz/);
    await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', /digital game licenses/i);
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', /127\.0\.0\.1:8173/);
    await expect(page.locator('h1')).toBeVisible();

    await page.goto('/downloads');
    await expect(page).toHaveTitle(/Downloads - Aksa Xiterz/);
    await expect(page.locator('h1')).toBeVisible();

    await page.goto('/guides');
    await expect(page).toHaveTitle(/Setup Guides - Aksa Xiterz/);
    await expect(page.locator('h1')).toBeVisible();

    expect(consoleErrors).toEqual([]);
});

test('operational and crawler endpoints respond correctly', async ({ request }) => {
    const health = await request.get('/up');
    expect(health.ok()).toBeTruthy();

    const sitemap = await request.get('/sitemap.xml');
    expect(sitemap.ok()).toBeTruthy();
    expect(sitemap.headers()['content-type']).toContain('application/xml');
    expect(await sitemap.text()).toContain('<urlset');

    const stocks = await request.get('/api/product-stocks');
    expect(stocks.ok()).toBeTruthy();
});

test('payment dialog traps keyboard focus and restores the opener', async ({ page }) => {
    await page.goto('/');

    await page.evaluate(() => {
        const opener = document.createElement('button');
        opener.id = 'testPaymentOpener';
        opener.textContent = 'Open payment result';
        document.body.appendChild(opener);

        const modal = document.createElement('div');
        modal.id = 'aksaPaymentSuccessModal';
        modal.className = 'qris-modal hidden';
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = `
            <section class="qris-dialog" role="dialog" aria-modal="true" aria-labelledby="aksaPaymentSuccessTitle">
                <button type="button" data-payment-success-close aria-label="Close payment success">Close</button>
                <div class="payment-success-mark" aria-hidden="true">
                    <svg class="payment-success-check" viewBox="0 0 32 32"><path d="M7 17l6 6L25 10" pathLength="1"></path></svg>
                </div>
                <h2 id="aksaPaymentSuccessTitle">Payment Successful</h2>
                <p id="aksaPaymentSuccessMessage"></p>
                <p id="aksaPaymentSuccessCopyStatus"></p>
                <p id="aksaPaymentSuccessCountdown"></p>
                <a href="/licenses" id="aksaPaymentSuccessPrimary">View License</a>
            </section>`;
        document.body.appendChild(modal);

        opener.focus();
        window.showAksaPaymentSuccess({ redirectDelay: 60000 });
    });

    const close = page.getByRole('button', { name: 'Close payment success' });
    const primary = page.getByRole('link', { name: 'View License' });
    await expect(page.locator('.payment-success-mark')).toHaveClass(/is-animating/);
    await expect(close).toBeFocused();

    await page.keyboard.press('Shift+Tab');
    await expect(primary).toBeFocused();

    await page.keyboard.press('Tab');
    await expect(close).toBeFocused();

    await page.keyboard.press('Escape');
    await expect(page.locator('#aksaPaymentSuccessModal')).toHaveAttribute('aria-hidden', 'true');
    await expect(page.locator('#testPaymentOpener')).toBeFocused();
});

test('product filtering fails safely when its request is unavailable', async ({ page }) => {
    await page.route('**/products-fragment**', route => route.abort());
    await page.goto('/');

    await page.getByRole('button', { name: 'PC', exact: true }).click();

    await expect(page.getByText('Products could not be loaded. Please refresh the page and try again.')).toBeVisible();
    await expect(page.getByText('Products not loaded')).toBeVisible();
    await expect(page.locator('h1')).toBeVisible();
});

test('storefront reflows without horizontal clipping at a 200 percent equivalent viewport', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 568 });
    await page.goto('/');

    await expect(page.locator('h1')).toBeVisible();
    await expect(page.getByRole('textbox', { name: 'Search products' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'All', exact: true })).toBeVisible();

    const overflow = await page.evaluate(() => ({
        documentWidth: document.documentElement.scrollWidth,
        viewportWidth: document.documentElement.clientWidth,
    }));

    expect(overflow.documentWidth).toBeLessThanOrEqual(overflow.viewportWidth);
});

test('unknown pages use the branded recovery screen', async ({ page }) => {
    const response = await page.goto('/page-that-does-not-exist');

    expect(response?.status()).toBe(404);
    await expect(page.getByRole('heading', { name: 'Page not found' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Back to Products' })).toBeVisible();
});
