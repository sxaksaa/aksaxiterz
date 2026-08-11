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

    const productCards = page.locator('[data-product-stock-card]');
    const productCardCount = await productCards.count();
    expect(productCardCount).toBeGreaterThan(0);
    await expect(page.locator('[data-product-stock-card] .product-card-facts .product-card-fact'))
        .toHaveCount(productCardCount);
    expect((await productCards.allTextContents()).join(' ')).not.toMatch(/\bFrom \d+ days?\b/i);
    await expect(page.locator('[data-product-availability] [data-product-stock-label]').first())
        .toHaveCSS('white-space', 'normal');

    await page.goto('/downloads');
    await expect(page).toHaveTitle(/Downloads - Aksa Xiterz/);
    await expect(page.locator('h1')).toBeVisible();

    await page.goto('/guides');
    await expect(page).toHaveTitle(/Setup Guides - Aksa Xiterz/);
    await expect(page.locator('h1')).toBeVisible();

    expect(consoleErrors).toEqual([]);
});

test('product navigation stays in the same document with CSP enabled', async ({ page }) => {
    const consoleErrors = [];
    page.on('console', (message) => {
        if (message.type() === 'error') consoleErrors.push(message.text());
    });

    const response = await page.goto('/');
    expect(response?.headers()['content-security-policy']).toContain("script-src 'self' 'nonce-");

    await page.evaluate(() => {
        window.__aksaSoftNavigationMarker = 'same-document';
    });

    await page.locator('[data-product-stock-card]').first().click();
    await expect(page).toHaveURL(/\/product\//);
    await expect(page.locator('h1')).toBeVisible();

    expect(await page.evaluate(() => window.__aksaSoftNavigationMarker)).toBe('same-document');
    expect(consoleErrors.filter(message => /content security policy|unsafe-eval|refused to evaluate/i.test(message))).toEqual([]);
});

test('product focus prefetches detail once and navigation reuses it', async ({ page }) => {
    let detailRequests = 0;

    await page.route('**/product/**', async (route) => {
        if (route.request().headers()['x-requested-with'] === 'XMLHttpRequest') {
            detailRequests++;
        }

        await route.continue();
    });

    await page.goto('/');

    const product = page.locator('[data-product-stock-card]').first();
    await product.focus();
    await expect.poll(() => detailRequests).toBe(1);

    await product.click();
    await expect(page).toHaveURL(/\/product\//);
    await expect(page.locator('h1')).toBeVisible();
    expect(detailRequests).toBe(1);
});

test('safe footer navigation prefetches once and reuses the response', async ({ page }) => {
    let guideRequests = 0;

    await page.route('**/guides', async (route) => {
        if (route.request().headers()['x-requested-with'] === 'XMLHttpRequest') {
            guideRequests++;
        }

        await route.continue();
    });

    await page.goto('/');

    const guidesLink = page.locator('footer a[data-soft-nav][href$="/guides"]');
    await guidesLink.focus();
    await expect.poll(() => guideRequests).toBe(1);

    await guidesLink.click();
    await expect(page).toHaveURL(/\/guides$/);
    await expect(page.locator('h1')).toBeVisible();
    expect(guideRequests).toBe(1);
});

test('short product pages keep the footer at the viewport bottom naturally', async ({ page }) => {
    await page.setViewportSize({ width: 2560, height: 1440 });
    await page.goto('/');
    await page.locator('[data-product-stock-card]').first().click();
    await expect(page).toHaveURL(/\/product\//);

    const footer = page.locator('.site-footer');
    await expect(footer).toBeVisible();

    await expect(footer).toHaveCSS('position', 'relative');
    await expect.poll(() => footer.evaluate((element) => (
        Math.abs(window.innerHeight - Math.round(element.getBoundingClientRect().bottom))
    ))).toBeLessThanOrEqual(1);
});

test('back to products restores homepage filters and scroll position', async ({ page }) => {
    const consoleErrors = [];
    page.on('console', (message) => {
        if (message.type() === 'error') consoleErrors.push(message.text());
    });

    await page.setViewportSize({ width: 390, height: 500 });
    await page.goto('/');

    await page.getByRole('button', { name: 'PC', exact: true }).click();
    const search = page.getByRole('textbox', { name: 'Search products' });
    const filteredProducts = page.waitForResponse((response) => {
        const url = new URL(response.url());

        return url.pathname === '/products-fragment'
            && url.searchParams.get('search') === 'Aurora VN'
            && response.ok();
    });
    await search.fill('Aurora VN');
    await filteredProducts;
    await expect(page.locator('#productContainer')).not.toHaveClass(/product-filter-(?:leaving|entering)|product-container-loading/);

    const product = page.locator('[data-product-stock-card]').first();
    await expect(product).toBeVisible();
    await product.scrollIntoViewIfNeeded();
    const savedScrollY = await page.evaluate(() => Math.round(window.scrollY));
    expect(savedScrollY).toBeGreaterThan(0);
    await expect.poll(() => page.evaluate(() => window.history.state)).toMatchObject({
        aksaHomeView: {
            search: 'Aurora VN',
            category: 'pc',
        },
        aksaScrollPosition: {
            y: savedScrollY,
        },
    });

    await product.click();
    await expect(page).toHaveURL(/\/product\//);
    await expect.poll(() => page.evaluate(() => window.history.state?.aksaPreviousUrl)).toMatch(/\/$/);
    await page.getByRole('link', { name: 'Back to products' }).click();

    await expect(page).toHaveURL('/');
    await expect(search).toBeVisible();
    await expect.poll(() => page.evaluate(() => window.history.state)).toMatchObject({
        aksaHomeView: {
            search: 'Aurora VN',
            category: 'pc',
        },
        aksaScrollPosition: {
            y: savedScrollY,
        },
    });
    expect(consoleErrors).toEqual([]);
    await expect(search).toHaveValue('Aurora VN');
    await expect(page.getByRole('button', { name: 'PC', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('[data-product-stock-card]').first()).toBeVisible();
    await expect.poll(() => page.evaluate(() => Math.round(window.scrollY))).toBe(savedScrollY);
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

test('empty product search can clear all filters', async ({ page }) => {
    await page.goto('/');

    const search = page.getByRole('textbox', { name: 'Search products' });
    await search.fill('product-that-does-not-exist-anywhere');
    await expect(page.getByText('No products found')).toBeVisible();

    await page.getByRole('button', { name: 'Clear Filters' }).click();

    await expect(search).toHaveValue('');
    await expect(page.locator('[data-product-stock-card]').first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'All', exact: true })).toHaveAttribute('aria-pressed', 'true');
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
