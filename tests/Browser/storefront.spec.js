import { expect, test } from '@playwright/test';

test('branded intro plays once per tab session and releases the storefront', async ({ page }) => {
    await page.goto('/?intro-e2e=1');

    await expect(page.locator('#aksaSiteIntro')).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('data-aksa-intro-state', 'running');

    const introOpeningState = await page.evaluate(() => ({
        backgroundImage: getComputedStyle(document.querySelector('#aksaSiteIntro')).backgroundImage,
        backgroundColor: getComputedStyle(document.querySelector('#aksaSiteIntro')).backgroundColor,
        pageContentOpacity: getComputedStyle(document.querySelector('[data-aksa-page-content]')).opacity,
        layoutWidth: document.documentElement.clientWidth,
    }));

    expect(introOpeningState).toMatchObject({
        backgroundImage: 'none',
        backgroundColor: 'rgba(0, 0, 0, 0)',
        pageContentOpacity: '0',
    });

    await expect(page.locator('html')).toHaveClass(/aksa-intro-nav-ready/);

    const navbarExpansionState = await page.evaluate(() => ({
        introLogoOpacity: getComputedStyle(document.querySelector('[data-site-intro-lockup]')).opacity,
        navbarLogoOpacity: getComputedStyle(document.querySelector('[data-site-brand-logo]')).opacity,
        navbarActionsOpacity: getComputedStyle(document.querySelector('[data-navbar-actions]')).opacity,
    }));

    expect(navbarExpansionState).toEqual({
        introLogoOpacity: '1',
        navbarLogoOpacity: '0',
        navbarActionsOpacity: '0',
    });

    await expect(page.locator('html')).toHaveClass(/aksa-intro-logo-handoff/);
    await page.waitForTimeout(360);

    const logoHandoffState = await page.evaluate(() => {
        const introLogo = document.querySelector('[data-site-intro-lockup]');
        const navbarLogo = document.querySelector('[data-site-brand-logo]');
        const introRect = introLogo.getBoundingClientRect();
        const navbarRect = navbarLogo.getBoundingClientRect();

        return {
            introLogoOpacity: Number(getComputedStyle(introLogo).opacity),
            navbarLogoOpacity: Number(getComputedStyle(navbarLogo).opacity),
            navbarActionsOpacity: Number(getComputedStyle(document.querySelector('[data-navbar-actions]')).opacity),
            introLeft: introRect.left,
            navbarLeft: navbarRect.left,
            viewportWidth: window.innerWidth,
            layoutWidth: document.documentElement.clientWidth,
            horizontalDifference: Math.abs(introRect.left - navbarRect.left),
            verticalDifference: Math.abs(introRect.top - navbarRect.top),
        };
    });

    expect(logoHandoffState.introLogoOpacity).toBeLessThan(0.05);
    expect(logoHandoffState.navbarLogoOpacity).toBeGreaterThan(0.95);
    expect(logoHandoffState.navbarActionsOpacity).toBeGreaterThan(0.95);
    expect(logoHandoffState.horizontalDifference, JSON.stringify(logoHandoffState)).toBeLessThan(2);
    expect(logoHandoffState.verticalDifference).toBeLessThan(2);

    await expect(page.locator('html')).toHaveClass(/aksa-home-reveal-ready/);

    const revealSequence = await page.evaluate(() => {
        const delayFor = (selector) => Number.parseFloat(
            getComputedStyle(document.querySelector(selector)).animationDelay,
        );
        const cardDelays = [...document.querySelectorAll('[data-home-reveal-stage="product-card"]')]
            .slice(0, 4)
            .map((card) => Number.parseFloat(getComputedStyle(card).animationDelay));

        return {
            stages: [
                delayFor('[data-home-reveal-stage="hero-title"]'),
                delayFor('[data-home-reveal-stage="hero-action"]'),
                delayFor('[data-home-reveal-stage="proof"]'),
                delayFor('[data-home-reveal-stage="tools"]'),
                delayFor('[data-home-reveal-stage="search"]'),
            ],
            cardDelays,
            recentPurchaseHidden: document.querySelector('[data-recent-purchase-toast]')?.hidden,
        };
    });

    expect(revealSequence.stages).toEqual([0, 0.18, 0.36, 0.6, 0.78]);
    expect(revealSequence.cardDelays).toEqual([1, 1.11, 1.22, 1.33]);
    expect(revealSequence.recentPurchaseHidden).toBe(true);

    await expect(page.locator('html')).toHaveAttribute('data-aksa-intro-state', 'complete', {
        timeout: 4000,
    });
    await expect(page.locator('#aksaSiteIntro')).toHaveCount(0);
    await expect(page.locator('.site-navbar-pill')).toBeVisible();
    await expect(page.locator('[data-aksa-page-content]')).toBeVisible();

    const releasedLayoutWidth = await page.evaluate(() => document.documentElement.clientWidth);
    expect(releasedLayoutWidth).toBe(introOpeningState.layoutWidth);

    await page.reload();

    await expect(page.locator('html')).toHaveAttribute('data-aksa-intro-state', 'skipped');
    await expect(page.locator('#aksaSiteIntro')).toHaveCount(0);
});

test('branded intro skips motion when reduced motion is preferred', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto('/?intro-reduced-motion=1');

    await expect(page.locator('html')).toHaveAttribute('data-aksa-intro-state', 'skipped');
    await expect(page.locator('#aksaSiteIntro')).toHaveCount(0);
    await expect(page.locator('.site-navbar-pill')).toBeVisible();
    await expect(page.locator('[data-aksa-page-content]')).toBeVisible();
});

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

    const heroGlow = await page.locator('.home-hero').evaluate((element) => {
        const style = window.getComputedStyle(element, '::before');

        return {
            height: style.height,
            maskImage: style.maskImage || style.webkitMaskImage,
        };
    });
    expect(heroGlow.height).toBe('576px');
    expect(heroGlow.maskImage).toContain('linear-gradient');

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

test('product checkout summary uses the restrained purple hierarchy', async ({ page }) => {
    await page.goto('/');
    await page.locator('[data-product-stock-card]').first().click();

    const availablePackage = page.locator('[data-package-checkout-enabled="true"]').first();
    await expect(availablePackage).toBeVisible();
    await availablePackage.click();

    const summary = page.locator('#summaryBox');
    await expect(summary).toBeVisible();
    await expect(summary).toHaveCSS('border-color', 'rgba(180, 155, 255, 0.2)');
    await expect(summary.locator('.summary-row').first()).toHaveCSS('border-color', 'rgba(180, 155, 255, 0.1)');
    await expect(page.locator('#selectedSubtotal')).toHaveCSS('color', 'rgb(196, 181, 253)');

    const columnCount = await page.locator('.product-summary-details').evaluate((element) => (
        window.getComputedStyle(element).gridTemplateColumns.split(' ').length
    ));
    expect(columnCount).toBe(page.viewportSize().width >= 1024 ? 4 : 1);
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

test('scroll reveals repeat from the side an item re-enters', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 500 });
    await page.goto('/');

    const product = page.locator('[data-product-stock-card]').first();
    await expect(product).toHaveAttribute('data-motion-reveal-from', 'bottom');
    await product.scrollIntoViewIfNeeded();
    await expect(product).toHaveClass(/is-scroll-revealed/);

    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await expect(product).not.toHaveClass(/is-scroll-revealed/);
    await expect(product).toHaveAttribute('data-motion-reveal-from', 'top');

    await product.scrollIntoViewIfNeeded();
    await expect(product).toHaveClass(/is-scroll-revealed/);
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
