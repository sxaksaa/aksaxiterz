import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

test('payment timer fills the mobile row and continues counting down', async ({ page }, testInfo) => {
    const html = readFileSync(join(testInfo.config.metadata.browserSandbox, 'payment.html'), 'utf8');
    await page.route('**/payment-layout-fixture', route => route.fulfill({ contentType: 'text/html', body: html }));
    await page.goto('/payment-layout-fixture');
    const header = page.locator('.payment-instruction-header');
    const timer = header.locator('[data-payment-countdown]');
    await expect(timer).toHaveText(/\d+m \d+s/);
    const initial = await timer.textContent();
    await expect(timer).not.toHaveText(initial);

    for (const width of [320, 390, 430, 1280]) {
        await page.setViewportSize({ width, height: 850 });
        await header.scrollIntoViewIfNeeded();
        const boxes = await header.evaluate(el => {
            const box = node => {
                const rect = node.getBoundingClientRect();
                return { x: rect.x, y: rect.y, right: rect.right, bottom: rect.bottom, width: rect.width };
            };
            const countdown = el.querySelector('.payment-instruction-countdown');
            return {
                header: box(el), title: box(el.firstElementChild),
                group: box(countdown), label: box(countdown.children[0]),
                time: box(countdown.children[1]), note: box(countdown.children[2]),
                overflows: document.documentElement.scrollWidth > innerWidth,
            };
        });
        expect(boxes.overflows).toBe(false);
        if (width < 640) {
            expect(Math.abs(boxes.group.width - boxes.header.width)).toBeLessThan(1);
            expect(boxes.group.y).toBeGreaterThanOrEqual(boxes.title.bottom);
            expect(Math.abs(boxes.label.y - boxes.time.y)).toBeLessThan(6);
            expect(boxes.time.x).toBeGreaterThan(boxes.label.x);
            expect(boxes.note.y).toBeGreaterThanOrEqual(boxes.time.bottom);
        } else {
            expect(Math.abs(boxes.title.y - boxes.group.y)).toBeLessThan(1);
        }
        if (width === 390) await page.screenshot({ path: testInfo.outputPath('payment-mobile.png') });
    }
});
