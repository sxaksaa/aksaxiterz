import { test, expect } from '@playwright/test';

test('payment code loads on demand once, while checkout code stays out of public pages', async ({ page }) => {
    const scripts = [];
    page.on('request', request => { if (request.resourceType() === 'script') scripts.push(request.url()); });
    await page.goto('/guides');
    await page.waitForFunction(() => typeof window.closeAksaQrisModal === 'function');
    expect(scripts.filter(url => /\/(checkout|payments)-/.test(url))).toHaveLength(0);
    await page.evaluate(() => Promise.all([window.closeAksaQrisModal(), window.closeAksaCryptoModal()]));
    expect(scripts.filter(url => /\/payments-/.test(url))).toHaveLength(1);
    expect(scripts.filter(url => /\/checkout-/.test(url))).toHaveLength(0);
});

test('mobile animation profile under CPU throttling preserves scrolling and currency updates', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile-chromium', 'Profiles the mobile rendering path.');
    const session = await page.context().newCDPSession(page);
    await session.send('Emulation.setCPUThrottlingRate', { rate: 4 });
    await session.send('Performance.enable');
    await page.addInitScript(() => {
        window.profileLongTasks = [];
        new PerformanceObserver(list => {
            window.profileLongTasks.push(...list.getEntries().map(entry => ({ start: entry.startTime, duration: entry.duration })));
        }).observe({ type: 'longtask', buffered: true });
    });
    await page.goto('/');
    await page.waitForFunction(() => typeof window.setAksaDisplayCurrency === 'function');
    await expect(page.locator('html')).toHaveAttribute('data-aksa-intro-state', /complete|skipped/);
    const before = await session.send('Performance.getMetrics');
    const frames = await page.evaluate(async () => {
        const gaps = [];
        let previous = performance.now();
        for (let index = 0; index < 30; index++) {
            const now = await new Promise(resolve => requestAnimationFrame(resolve));
            gaps.push(now - previous);
            previous = now;
            window.scrollTo(0, index * 45);
        }
        window.setAksaDisplayCurrency('usd', { source: 'manual' });
        return gaps;
    });
    await expect(page.locator('html')).toHaveAttribute('data-display-currency', 'usd');
    await expect(page.locator('[data-currency-switcher].is-currency-switching')).toHaveCount(0);
    const after = await session.send('Performance.getMetrics');
    const value = (metrics, name) => metrics.metrics.find(metric => metric.name === name)?.value || 0;
    const report = {
        environment: 'Pixel 7 emulation, desktop CPU throttled 4x; not physical-device FPS',
        scrollFrameSamples: frames.length,
        framesOver50ms: frames.filter(gap => gap > 50).length,
        maxFrameGapMs: Math.max(...frames),
        layoutsDuringInteraction: value(after, 'LayoutCount') - value(before, 'LayoutCount'),
        scriptSecondsDuringInteraction: value(after, 'ScriptDuration') - value(before, 'ScriptDuration'),
        longTasks: await page.evaluate(() => window.profileLongTasks),
    };
    await testInfo.attach('mobile-performance.json', { body: JSON.stringify(report, null, 2), contentType: 'application/json' });
    console.log('Mobile performance profile:', JSON.stringify(report));
});
