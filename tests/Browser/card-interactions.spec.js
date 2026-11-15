import { expect, test } from '@playwright/test';

test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => sessionStorage.setItem('aksa_site_intro_seen_v1', 'true'));
});

const motion = el => {
    const style = getComputedStyle(el);
    const properties = style.transitionProperty.split(',').map(value => value.trim());
    const durations = style.transitionDuration.split(',').map(Number.parseFloat);
    return {
        translateDuration: durations[properties.indexOf('translate') % durations.length],
        translate: style.translate,
        scale: style.scale,
        transform: style.transform,
    };
};

test('catalog and package hover share timing and return smoothly to rest', async ({ page, isMobile }, testInfo) => {
    test.skip(isMobile, 'Hover belongs to devices with a fine pointer.');
    await page.goto('/');
    const product = page.locator('[data-product-stock-card]').first();
    await product.scrollIntoViewIfNeeded();
    await expect(product).toHaveClass(/is-scroll-revealed/);
    await product.hover();
    await expect.poll(async () => (await product.evaluate(motion)).translate).toBe('0px -8px');
    const catalogTiming = (await product.evaluate(motion)).translateDuration;
    expect(catalogTiming).toBeGreaterThan(0);
    await page.screenshot({ path: testInfo.outputPath('catalog-hover.png') });
    await page.mouse.move(0, 0);
    await expect.poll(async () => (await product.evaluate(motion)).scale).toBe('1');
    const href = await product.getAttribute('href');
    await page.goto(href);
    const pack = page.locator('[data-package-checkout-enabled="true"]').first();
    await pack.scrollIntoViewIfNeeded();
    await expect(pack).toHaveClass(/is-scroll-revealed/);
    await pack.hover();
    await expect.poll(async () => (await pack.evaluate(motion)).translate).toBe('0px -5px');
    expect((await pack.evaluate(motion)).translateDuration).toBe(catalogTiming);
    await page.screenshot({ path: testInfo.outputPath('package-hover.png') });
    await page.mouse.move(0, 0);
    await expect.poll(async () => (await pack.evaluate(motion)).scale).toBe('1');
});

test('wide touch screens can open downloads without a sticky card lift', async ({ page, isMobile }) => {
    test.skip(!isMobile, 'Uses the real touch-enabled browser context.');
    await page.setViewportSize({ width: 1100, height: 900 });
    await page.goto('/downloads');
    expect(await page.evaluate(() => matchMedia('(hover: none) and (pointer: coarse)').matches)).toBe(true);
    const card = page.locator('[data-download-accordion]').first();
    await card.locator('summary').tap();
    await expect(card).toHaveAttribute('open', '');
    await expect.poll(async () => (await card.evaluate(motion)).transform).toBe('none');
    await expect(card.locator('[data-download-resource]').first()).toBeVisible();
});

test('reduced motion keeps focused product cards still and visible', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto('/');
    const product = page.locator('[data-product-stock-card]').first();
    await product.focus();
    await expect(product).toBeVisible();
    const state = await product.evaluate(motion);
    expect(['none', '0px']).toContain(state.translate);
    expect(['none', '1']).toContain(state.scale);
});
