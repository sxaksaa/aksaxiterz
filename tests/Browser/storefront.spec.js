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
