import { expect, test } from '@playwright/test';

test('chart tooltips escape clipped cards and remain within the viewport', async ({ page }) => {
    await page.addInitScript(() => sessionStorage.setItem('aksa_site_intro_seen_v1', 'true'));
    await page.goto('/');
    await page.evaluate(() => {
        const card = document.createElement('section');
        card.dataset.tooltipFixture = '';
        Object.assign(card.style, { position: 'fixed', top: '70px', left: '16px', width: 'calc(100vw - 32px)', maxWidth: '600px', overflow: 'hidden', transform: 'translateY(0)', zIndex: '60' });
        card.innerHTML = `<div class="dashboard-chart-frame" data-dashboard-chart>
            <div id="testChartTooltip" class="dashboard-chart-tooltip" data-dashboard-chart-tooltip aria-hidden="true">
                <div class="dashboard-chart-tooltip-title" data-chart-tooltip-title></div>
                ${['orders', 'line', 'idr', 'crypto'].map(key => `<div class="dashboard-chart-tooltip-row"><span>${key}</span><strong data-chart-tooltip-${key}></strong></div>`).join('')}
            </div>
            <svg viewBox="0 0 400 100" width="100%" height="100">
                ${[5, 200, 395].map((x, i) => `<rect x="${x - 4}" y="6" width="8" height="8" tabindex="0" data-chart-point data-x="${x}" data-y="10" data-label="Nov 2026" data-orders="329" data-line="Rp 17.022.000" data-idr="Rp 11.915.011" data-crypto="$360.41" aria-label="Month ${i}" />`).join('')}
            </svg></div>`;
        document.body.append(card);
    });
    const points = page.locator('[data-tooltip-fixture] [data-chart-point]');
    const tooltip = page.locator('#testChartTooltip');
    const assertFits = async () => {
        await expect(tooltip).toHaveAttribute('aria-hidden', 'false');
        await expect(tooltip.locator('[data-chart-tooltip-crypto]')).toHaveText('$360.41');
        const box = await tooltip.boundingBox();
        expect(box.x).toBeGreaterThanOrEqual(11);
        expect(box.y).toBeGreaterThanOrEqual(11);
        expect(box.x + box.width).toBeLessThanOrEqual(page.viewportSize().width - 11);
        expect(box.y + box.height).toBeLessThanOrEqual(page.viewportSize().height - 11);
        expect(await tooltip.evaluate(el => el.parentElement === document.body)).toBe(true);
    };
    for (let i = 0; i < 3; i++) {
        await points.nth(i).focus();
        await assertFits();
        await expect(points.nth(i)).toHaveAttribute('aria-describedby', 'testChartTooltip');
    }
    await expect(tooltip).toHaveClass(/is-below/);
    await page.keyboard.press('Escape');
    await expect(tooltip).toHaveAttribute('aria-hidden', 'true');
    await page.evaluate(() => {
        const card = document.querySelector('[data-tooltip-fixture]');
        card.style.top = 'auto';
        card.style.bottom = '20px';
    });
    await points.first().focus();
    await assertFits();
    await expect(tooltip).not.toHaveClass(/is-below/);
    await page.evaluate(() => document.dispatchEvent(new Event('scroll')));
    await expect(tooltip).toHaveAttribute('aria-hidden', 'true');
    await points.last().focus();
    await assertFits();
    await page.evaluate(() => document.dispatchEvent(new Event('aksa:before-page-swap')));
    await expect(page.locator('body > [data-dashboard-chart-tooltip]')).toHaveCount(0);
});
