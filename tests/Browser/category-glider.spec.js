import { expect, test } from '@playwright/test';

test('category pill previews without filtering, then commits the selection', async ({ page, isMobile }) => {
    await page.addInitScript(() => sessionStorage.setItem('aksa_site_intro_seen_v1', 'true'));
    await page.goto('/');
    const chips = page.locator('[data-category-filter]');
    const all = chips.first();
    const other = chips.nth(1);
    const glider = page.locator('[data-category-filter-glider]');
    await expect(glider).toHaveClass(/is-visible/);
    const aligned = () => glider.evaluate(el => {
        const chip = document.querySelector('[data-category-filter].is-glider-target');
        return el.style.transform === `translate3d(${chip.offsetLeft}px, 0px, 0px)` &&
            Number.parseFloat(el.style.width) === chip.offsetWidth;
    });
    if (!isMobile) {
        const productLinks = page.locator('#productContainer a[href*="/product/"]');
        const before = await productLinks.evaluateAll(links => links.map(link => link.href));
        expect(before.length).toBeGreaterThan(0);
        const url = page.url();
        await other.hover();
        await expect(other).toHaveClass(/is-glider-target/);
        await expect(all).toHaveAttribute('aria-pressed', 'true');
        await expect(other).toHaveAttribute('aria-pressed', 'false');
        expect(page.url()).toBe(url);
        expect(await productLinks.evaluateAll(links => links.map(link => link.href))).toEqual(before);
        await expect.poll(aligned).toBe(true);
        await page.mouse.move(0, 0);
        await expect(all).toHaveClass(/is-glider-target/);
    }
    await other.focus();
    await expect(other).toHaveClass(/is-glider-target/);
    await expect(all).toHaveAttribute('aria-pressed', 'true');
    await other.blur();
    await expect(all).toHaveClass(/is-glider-target/);
    if (isMobile) await other.tap();
    else await other.click();
    await expect(other).toHaveAttribute('aria-pressed', 'true');
    await page.mouse.move(0, 0);
    await expect(other).toHaveClass(/is-glider-target/);
    await expect.poll(aligned).toBe(true);
});

test('category pill honors reduced motion', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto('/');
    const chip = page.locator('[data-category-filter]').nth(1);
    await chip.focus();
    await expect(chip).toHaveClass(/is-glider-target/);
    const duration = await page.locator('[data-category-filter-glider]').evaluate(el =>
        Math.max(...getComputedStyle(el).transitionDuration.split(',').map(Number.parseFloat)));
    expect(duration).toBeLessThanOrEqual(0.001);
});
