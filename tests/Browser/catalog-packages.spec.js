import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

async function openCatalog(page, testInfo) {
    const html = readFileSync(join(testInfo.config.metadata.browserSandbox, 'catalog.html'), 'utf8');
    await page.route('**/catalog-packages-fixture', route => route.fulfill({ contentType: 'text/html', body: html }));
    await page.goto('/catalog-packages-fixture');
    const product = page.locator('[data-catalog-accordion]').first();
    await product.locator(':scope > summary').click();
    await expect(product).toHaveJSProperty('open', true);
    return product;
}

test('product fields edit immediately and save folded package values', async ({ page }, testInfo) => {
    const product = await openCatalog(page, testInfo);
    await expect(page.getByText(/\d+ records/, { exact: true })).toHaveCount(0);
    await expect(product.getByRole('button', { name: 'Edit', exact: true })).toHaveCount(0);
    const name = product.locator('input[name="name"]');
    await expect(name).toBeEnabled();
    await name.fill('Updated immediately');
    await expect(product.locator('[data-package-prices]')).toHaveJSProperty('open', false);
    const form = product.locator('[data-catalog-edit-form]');
    let submitted;
    await page.route(await form.getAttribute('action'), route => {
        submitted = new URLSearchParams(route.request().postData());
        return route.fulfill({ contentType: 'text/html', body: '<h1>Saved</h1>' });
    });
    await product.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Saved', exact: true })).toBeVisible();
    expect(submitted.get('_method')).toBe('PATCH');
    expect(submitted.get('name')).toBe('Updated immediately');
    const packagePrice = [...submitted].find(([key]) => key.endsWith('[price]'));
    expect(packagePrice?.[1]).toBe('20000');
});

test('package prices and add package start folded and open independently', async ({ page }, testInfo) => {
    const product = await openCatalog(page, testInfo);
    const prices = product.locator('[data-package-prices]');
    const add = product.locator('[data-add-package]');
    await expect(prices).toHaveJSProperty('open', false);
    await expect(add).toHaveJSProperty('open', false);
    const chevron = prices.locator(':scope > summary .catalog-chevron');
    await expect.poll(() => chevron.evaluate(el => getComputedStyle(el).transform)).toBe('none');
    await prices.locator(':scope > summary').press('Enter');
    await expect(prices).toHaveJSProperty('open', true);
    await expect(prices.locator('input[type="number"]').first()).toBeVisible();
    await expect(prices.locator('input[type="number"]').first()).toBeEnabled();
    await add.locator(':scope > summary').click();
    await expect(add).toHaveJSProperty('open', true);
    await prices.locator(':scope > summary').click();
    await expect(prices).toHaveJSProperty('open', false);
    await expect(add).toHaveJSProperty('open', true);
    await expect(product).toHaveJSProperty('open', true);
    await expect(prices.locator('input[type="number"]').first()).toBeHidden();
    await expect(add.locator('input[name="package_price"]')).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath('catalog-folded-prices.png') });
});

test('saving a collapsed invalid package reveals and focuses its input', async ({ page }, testInfo) => {
    const product = await openCatalog(page, testInfo);
    await expect(product.getByRole('button', { name: 'Edit', exact: true })).toHaveCount(0);
    const prices = product.locator('[data-package-prices]');
    await prices.locator(':scope > summary').click();
    const input = prices.locator('input[name$="[price]"]');
    await input.fill('');
    await prices.locator(':scope > summary').click();
    await expect(prices).toHaveJSProperty('open', false);
    await product.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(prices).toHaveJSProperty('open', true);
    await expect(input).toBeVisible();
    await expect(input).toBeFocused();
    await expect(product.locator('[data-add-package]')).toHaveJSProperty('open', false);
    expect(page.url()).toContain('/catalog-packages-fixture');
});
