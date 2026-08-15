import { expect, test } from '@playwright/test';

test('branded intro plays once per tab session and releases the storefront', async ({ page }) => {
    await page.addInitScript(() => {
        window.__homeCounterStates = [];
        const recordCounterState = () => {
            document.querySelectorAll('[data-home-count-up-state]').forEach((counter) => {
                const state = counter.getAttribute('data-home-count-up-state');
                if (state && !window.__homeCounterStates.includes(state)) {
                    window.__homeCounterStates.push(state);
                }
            });
        };
        new MutationObserver(recordCounterState).observe(document, {
            attributes: true,
            attributeFilter: ['data-home-count-up-state'],
            childList: true,
            subtree: true,
        });
    });
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

    const deliveredCounter = page.locator('[data-home-count-up="5000"]');
    await expect.poll(() => page.evaluate(() => window.__homeCounterStates.includes('running'))).toBe(true);

    await expect(page.locator('html')).toHaveAttribute('data-aksa-intro-state', 'complete', {
        timeout: 4000,
    });
    await expect(page.locator('#aksaSiteIntro')).toHaveCount(0);
    await expect(page.locator('.site-navbar-pill')).toBeVisible();
    await expect(page.locator('[data-aksa-page-content]')).toBeVisible();
    await expect(deliveredCounter).toHaveAttribute('data-home-count-up-state', 'complete');
    await expect(deliveredCounter).toHaveText('5000+');

    const releasedLayoutWidth = await page.evaluate(() => document.documentElement.clientWidth);
    expect(releasedLayoutWidth).toBe(introOpeningState.layoutWidth);

    await page.reload();

    await expect(page.locator('html')).toHaveAttribute('data-aksa-intro-state', 'skipped');
    await expect(page.locator('#aksaSiteIntro')).toHaveCount(0);
});

test('branded intro skips motion when reduced motion is preferred', async ({ page }) => {
    await page.addInitScript(() => window.localStorage.setItem('aksa_display_currency', 'idr'));
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto('/?intro-reduced-motion=1');

    await expect(page.locator('html')).toHaveAttribute('data-aksa-intro-state', 'skipped');
    await expect(page.locator('#aksaSiteIntro')).toHaveCount(0);
    await expect(page.locator('.site-navbar-pill')).toBeVisible();
    await expect(page.locator('[data-aksa-page-content]')).toBeVisible();
    await expect(page.locator('[data-home-count-up="5000"]')).toHaveText('5000+');

    const reducedCurrencySwap = await page.evaluate(() => {
        window.setAksaDisplayCurrency('usd', { source: 'manual' });
        const price = document.querySelector('[data-product-stock-card] [data-display-price]');

        return {
            text: price.textContent,
            state: price.dataset.currencySwapState || null,
        };
    });
    expect(reducedCurrencySwap.text).toContain('$');
    expect(reducedCurrencySwap.state).toBeNull();
});

test('license actions and download launch feedback use the themed motion states', async ({ page }) => {
    await page.goto('/');

    const licenseKey = page.locator('[data-license-motion-test]');
    await page.evaluate(() => {
        const key = document.createElement('span');
        const button = document.createElement('button');

        key.dataset.licenseMotionTest = 'true';
        key.dataset.licenseKeyValue = 'AKSA-TEST-12345678';
        key.dataset.licenseMasked = 'true';
        key.id = 'key-motion-test';
        key.textContent = 'AKSA-••••••••-5678';
        button.type = 'button';
        button.dataset.revealLicense = 'motion-test';
        button.innerHTML = '<span data-button-label>Reveal</span>';
        document.querySelector('main')?.append(key, button);
    });

    await page.locator('[data-reveal-license="motion-test"]').click();
    await expect(licenseKey).toHaveAttribute('data-license-masked', 'false');
    await expect(licenseKey.locator('.license-key-character')).toHaveCount(18);
    expect(await licenseKey.locator('.license-key-character').first().evaluate(
        character => getComputedStyle(character).animationName,
    )).toContain('license-character-reveal');

    const copyButton = page.locator('[data-copy-license="motion-test"]');
    await page.evaluate(() => {
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText: async () => {} },
        });

        const box = document.createElement('div');
        const button = document.createElement('button');

        box.className = 'license-key-box';
        button.type = 'button';
        button.dataset.copyLicense = 'motion-test';
        button.className = 'order-action btn-press';
        button.innerHTML = '<svg aria-hidden="true"></svg><span data-button-label>Copy</span>';
        box.append(button);
        document.querySelector('main')?.append(box);
    });

    await copyButton.click();
    await expect(copyButton.locator('[data-button-label]')).toHaveText('Copied');
    await expect(copyButton).toHaveClass(/text-aksa-accent-soft/);
    await expect(copyButton).not.toHaveClass(/text-green-400/);
    await expect(copyButton.locator('svg')).toHaveCSS('display', 'none');
    expect(await copyButton.evaluate(button => getComputedStyle(button, '::before').content)).toBe('"✓"');
    expect(await copyButton.evaluate(button => getComputedStyle(button, '::after').content)).toBe('none');
    await expect(copyButton).toHaveCSS('color', 'rgb(180, 155, 255)');
    await copyButton.hover();
    await expect(copyButton).toHaveCSS('color', 'rgb(180, 155, 255)');

    await page.goto('/downloads');
    const downloadLink = page.locator('[data-download-motion-test]');
    await page.evaluate(() => {
        const link = document.createElement('a');
        link.href = '#download-motion-test';
        link.dataset.downloadMotionTest = 'true';
        link.dataset.downloadResource = '';
        link.dataset.downloadCompleteLabel = 'Download started';
        link.className = 'download-resource-link';
        link.innerHTML = '<span class="download-resource-icon"><svg></svg></span><span data-download-resource-label>Download Loader</span>';
        link.addEventListener('click', event => event.preventDefault(), { capture: true });
        document.querySelector('main')?.appendChild(link);
    });

    await downloadLink.click();
    await expect(downloadLink).toHaveClass(/is-download-launching/);
    await expect(downloadLink.locator('[data-download-resource-label]')).toHaveText('Opening...');
    await expect(downloadLink).toHaveClass(/is-download-complete/);
    await expect(downloadLink.locator('[data-download-resource-label]')).toHaveText('Download started');
});

test('cart removal commits and Undo restores the original package quantity', async ({ page }) => {
    let removeRequests = 0;
    let restoreRequests = 0;
    await page.route('**/cart/items/123', async route => {
        removeRequests++;
        await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ message: 'Removed' }) });
    });
    await page.route('**/cart/items/product-slug', async route => {
        restoreRequests++;
        await route.fulfill({
            contentType: 'application/json',
            body: JSON.stringify({ cart_count: 2, item_id: 456 }),
        });
    });
    await page.goto('/');

    await page.evaluate(() => {
        const fixture = document.createElement('section');
        fixture.innerHTML = `
            <h2 id="cartBundleCount" data-cart-distinct-items="1" data-cart-total-quantity="2">1 package · 2 licenses</h2>
            <span data-cart-subtotal data-display-price data-price-idr="40000" data-price-usd="2">Rp 40.000</span>
            <article data-cart-product-group>
                <p data-cart-group-count>1 package selected</p>
                <div class="cart-package-row" data-cart-item="123" data-cart-item-quantity="2"
                    data-cart-package-id="44" data-cart-restore-url="/cart/items/product-slug">
                    <p class="font-semibold text-white">30 Days</p>
                    <span data-cart-line-total data-price-idr="40000" data-price-usd="2"></span>
                    <form method="POST" action="/cart/items/123" data-cart-remove-form>
                        <input type="hidden" name="_method" value="DELETE">
                        <button type="submit">Remove package</button>
                    </form>
                </div>
            </article>
        `;
        document.querySelector('main')?.append(fixture);
    });

    const row = page.locator('[data-cart-product-group] .cart-package-row');
    await page.getByRole('button', { name: 'Remove package' }).click();
    await expect(row).toHaveClass(/cart-item-removing/);
    await expect(page.locator('#cartBundleCount')).toHaveText('0 packages · 0 licenses');
    await expect(page.locator('[data-cart-group-count]')).toHaveText('0 packages selected');
    await expect(page.getByRole('button', { name: 'Undo' })).toBeVisible();
    expect(removeRequests).toBe(1);

    await page.getByRole('button', { name: 'Undo' }).click();
    await expect(row).not.toHaveClass(/cart-item-removing/);
    await expect(row).toHaveClass(/cart-item-restored/);
    await expect(row).toHaveAttribute('data-cart-item', '456');
    await expect(row.locator('[data-cart-remove-form]')).toHaveAttribute('action', /\/cart\/items\/456$/);
    await expect(page.locator('#cartBundleCount')).toHaveText('1 package · 2 licenses');
    await expect(page.locator('[data-cart-group-count]')).toHaveText('1 package selected');
    expect(restoreRequests).toBe(1);
});

test('a server-confirmed checkout morphs into invoice ready before navigation', async ({ page }) => {
    await page.goto('/');
    await page.evaluate(() => {
        const summary = document.createElement('aside');
        const submit = document.createElement('button');

        summary.id = 'checkoutFinalSummary';
        submit.id = 'checkoutSubmitButton';
        submit.innerHTML = '<svg></svg><span data-button-label>Creating Invoice...</span>';
        document.querySelector('main')?.append(summary, submit);
    });

    const animation = page.evaluate(() => window.animateAksaCheckoutSuccess(
        document.getElementById('checkoutSubmitButton'),
        document.getElementById('checkoutFinalSummary'),
    ));
    const submit = page.locator('#checkoutSubmitButton');
    const summary = page.locator('#checkoutFinalSummary');

    await expect(submit).toHaveClass(/checkout-submit-success/);
    await expect(submit.locator('[data-button-label]')).toHaveText('Invoice Ready');
    await expect(submit.locator('svg')).toHaveCSS('display', 'none');
    await expect(summary).toHaveClass(/checkout-summary-success/);
    await animation;
});

test('license and order actions reveal their changed state one result at a time', async ({ page }) => {
    await page.goto('/');
    await page.evaluate(() => {
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText: async () => {} },
        });

        const fixture = document.createElement('section');
        fixture.innerHTML = `
            <input id="licenseSearch">
            <select id="licenseProductFilter"><option value="">All</option></select>
            <div id="licenseGroups">
                <article class="license-card" data-license-group data-license-product="1" data-license-search="alpha order-a">
                    <button type="button" data-copy-value="A" data-copy-all-licenses data-copy-success-label="3 Keys Copied">
                        <svg></svg><span data-button-label>Copy 3 Keys</span>
                    </button>
                </article>
                <article class="license-card" data-license-group data-license-product="2" data-license-search="beta order-b"></article>
            </div>
            <button type="button" class="license-reset-action is-reset-success" data-license-reset-success
                data-reset-final-label="Reset in 24h"><svg></svg><span data-button-label>Reset successful</span></button>
            <div id="ordersMotionFixture">
                <div data-order-filter-empty class="hidden"></div>
                <article data-order-entry data-order-status="pending">Pending order</article>
                <article data-order-entry data-order-status="paid">Paid order</article>
            </div>
        `;
        document.querySelector('main')?.append(fixture);
        window.initializeAksaPageEnhancements(fixture);
    });

    const reset = page.locator('[data-license-reset-success]');
    await expect(reset).toHaveClass(/is-reset-success/);
    await expect(reset.locator('[data-button-label]')).toHaveText('Reset in 24h', { timeout: 2500 });
    await expect(reset).not.toHaveClass(/is-reset-success/);

    const copyAll = page.locator('[data-copy-all-licenses]');
    await copyAll.click();
    await expect(copyAll.locator('[data-button-label]')).toHaveText('3 Keys Copied');
    await expect(copyAll).toHaveClass(/is-copy-all-success/);
    await expect(page.locator('[data-license-group]').first()).toHaveClass(/license-group-copy-success/);

    await page.locator('#licenseSearch').fill('beta');
    await expect(page.locator('[data-license-search="alpha order-a"]')).toHaveClass(/hidden/);
    await expect(page.locator('[data-license-search="beta order-b"]')).toHaveClass(/filter-result-enter/);

    await page.evaluate(() => {
        window.filterAksaOrderEntries(document.getElementById('ordersMotionFixture'), 'paid', { animate: true });
    });
    await expect(page.getByText('Pending order')).toHaveClass(/hidden/);
    await expect(page.getByText('Paid order')).toHaveClass(/filter-result-enter/);
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
    await expect(page.locator('h1')).toHaveText('Downloads');
    await expect(page.locator('.download-hero')).toHaveCount(0);
    await expect(page.getByRole('searchbox', { name: 'Search downloads...' })).toBeVisible();
    const downloadsTopGap = await page.locator('h1').evaluate((title) => (
        title.getBoundingClientRect().top - document.querySelector('.site-navbar-pill').getBoundingClientRect().bottom
    ));
    expect(downloadsTopGap).toBeGreaterThanOrEqual(24);

    await page.goto('/guides');
    await expect(page).toHaveTitle(/Setup Guides - Aksa Xiterz/);
    await expect(page.locator('h1')).toHaveText('Guides');
    await expect(page.locator('.download-hero')).toHaveCount(0);
    const guidesTopGap = await page.locator('h1').evaluate((title) => (
        title.getBoundingClientRect().top - document.querySelector('.site-navbar-pill').getBoundingClientRect().bottom
    ));
    expect(guidesTopGap).toBeGreaterThanOrEqual(24);

    expect(consoleErrors).toEqual([]);
});

test('currency switch moves the glider before staggered prices without shifting cards', async ({ page }) => {
    await page.addInitScript(() => window.localStorage.setItem('aksa_display_currency', 'idr'));
    await page.goto('/');
    await expect(page.locator('html')).toHaveAttribute('data-aksa-intro-state', 'complete', { timeout: 5000 });

    let usdButton = page.locator('[data-currency-option="usd"]:visible');
    if (await usdButton.count() === 0) {
        const menuButton = page.locator('[data-mobile-menu-toggle]');
        expect(await menuButton.count()).toBe(1);
        await menuButton.click();
        usdButton = page.locator('[data-currency-option="usd"]:visible');
    }
    expect(await usdButton.count()).toBe(1);

    const firstCard = page.locator('[data-product-stock-card]').first();
    const prices = page.locator('[data-product-stock-card] [data-display-price]');
    await expect(prices.first()).toContainText('Rp');
    const cardWidthBefore = await firstCard.evaluate(element => element.getBoundingClientRect().width);

    const immediateState = await page.evaluate(() => {
        window.__aksaCurrencySwapEvents = [];
        const watchedPrices = [...document.querySelectorAll('[data-product-stock-card] [data-display-price]')].slice(0, 2);
        const observer = new MutationObserver((records) => {
            records.forEach((record) => {
                if (record.attributeName !== 'data-currency-swap-state') return;

                const state = record.target.dataset.currencySwapState;
                if (state === 'entering') {
                    window.__aksaCurrencySwapEvents.push({
                        index: watchedPrices.indexOf(record.target),
                        time: performance.now(),
                    });
                }
            });
        });
        watchedPrices.forEach(price => observer.observe(price, { attributes: true }));

        const button = [...document.querySelectorAll('[data-currency-option="usd"]')]
            .find(option => option.getClientRects().length > 0);
        button.click();

        return {
            currency: document.documentElement.dataset.displayCurrency,
            price: watchedPrices[0].textContent,
            priceState: watchedPrices[0].dataset.currencySwapState,
            switcherAnimating: button.closest('[data-currency-switcher]').classList.contains('is-currency-switching'),
        };
    });

    expect(immediateState).toMatchObject({
        currency: 'usd',
        priceState: 'waiting',
        switcherAnimating: true,
    });
    expect(immediateState.price).toContain('Rp');
    await expect(prices.first()).toHaveAttribute('data-currency-swap-state', 'complete');
    await expect(prices.first()).toContainText('$');

    const enteringEvents = await page.evaluate(() => window.__aksaCurrencySwapEvents);
    expect(enteringEvents).toHaveLength(2);
    expect(enteringEvents[1].time - enteringEvents[0].time).toBeGreaterThanOrEqual(30);

    const cardWidthAfter = await firstCard.evaluate(element => element.getBoundingClientRect().width);
    expect(Math.abs(cardWidthAfter - cardWidthBefore)).toBeLessThanOrEqual(1);
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

test('navbar glider commits to the destination before soft navigation finishes', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 720 });
    await page.route('**/guides', async (route) => {
        if (route.request().headers()['x-requested-with'] === 'XMLHttpRequest') {
            await new Promise(resolve => setTimeout(resolve, 450));
        }

        await route.continue();
    });
    await page.goto('/');

    const guides = page.locator('#navMenu .nav-item[href$="/guides"]');
    await guides.click();
    await expect(guides).toHaveClass(/is-navigation-pending/);

    const gliderAligned = await page.locator('[data-nav-glider]').evaluate((glider) => {
        const link = document.querySelector('#navMenu .nav-item.is-navigation-pending');

        return glider.style.transform.includes(`${link.offsetLeft}px`);
    });
    expect(gliderAligned).toBe(true);

    await expect(page).toHaveURL(/\/guides$/);
    await expect(page.locator('#navMenu .nav-item[href$="/guides"]')).toHaveClass(/active/);
});

test('product checkout summary uses the restrained purple hierarchy', async ({ page }) => {
    await page.goto('/');
    await page.locator('[data-product-stock-card]').first().click();

    const availablePackage = page.locator('[data-package-checkout-enabled="true"]').first();
    await expect(availablePackage).toBeVisible();
    const packageAnimationName = await availablePackage.evaluate(element => {
        element.click();
        return getComputedStyle(element).animationName;
    });
    await expect(availablePackage).toHaveClass(/active/);
    expect(packageAnimationName).toContain('package-selection-pop');

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

test('mini cart highlights only the item most recently added', async ({ page }) => {
    await page.goto('/');

    await page.evaluate(() => {
        const root = document.createElement('div');
        root.dataset.miniCartRoot = '';
        root.innerHTML = `
            <a href="#" data-mini-cart-trigger aria-expanded="false">
                <span data-cart-count>1</span>
            </a>
            <div data-mini-cart-panel>
                <div data-mini-cart-content></div>
            </div>
        `;
        document.body.append(root);

        window.refreshAksaMiniCart(`
            <div data-mini-cart-content>
                <div class="mini-cart-items">
                    <div class="mini-cart-item" data-mini-cart-item-id="42">
                        <span class="mini-cart-item-icon">A</span>
                        <span>New package</span>
                    </div>
                    <div class="mini-cart-item" data-mini-cart-item-id="17">
                        <span class="mini-cart-item-icon">B</span>
                        <span>Existing package</span>
                    </div>
                </div>
            </div>
        `, 2, {
            bumpBadge: true,
            highlightItemId: 42,
        });
    });

    const root = page.locator('[data-mini-cart-root]');
    const highlightedItem = root.locator('[data-mini-cart-item-id="42"]');
    const existingItem = root.locator('[data-mini-cart-item-id="17"]');

    await expect(root).toHaveClass(/mini-cart-bump/);
    await expect(highlightedItem).toHaveClass(/is-cart-highlighted/);
    await expect(existingItem).not.toHaveClass(/is-cart-highlighted/);
    await expect(root).not.toHaveClass(/mini-cart-bump/, { timeout: 1500 });
    await expect(highlightedItem).not.toHaveClass(/is-cart-highlighted/, { timeout: 2500 });
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
    const initialScrollY = await page.evaluate(() => Math.round(window.scrollY));
    expect(initialScrollY).toBeGreaterThan(0);
    await expect.poll(() => page.evaluate(() => window.history.state)).toMatchObject({
        aksaHomeView: {
            search: 'Aurora VN',
            category: 'pc',
        },
        aksaScrollPosition: {
            y: initialScrollY,
        },
    });

    await product.evaluate((element) => {
        element.addEventListener('click', () => {
            window.__aksaNavigationClickScrollY = Math.round(window.scrollY);
        }, { capture: true, once: true });
    });
    await product.click();
    await expect(page).toHaveURL(/\/product\//);
    const navigationScrollY = await page.evaluate(() => window.__aksaNavigationClickScrollY);
    expect(navigationScrollY).toBeGreaterThan(0);
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
            y: navigationScrollY,
        },
    });
    expect(consoleErrors).toEqual([]);
    await expect(search).toHaveValue('Aurora VN');
    await expect(page.getByRole('button', { name: 'PC', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('[data-product-stock-card]').first()).toBeVisible();
    await expect.poll(() => page.evaluate(() => Math.round(window.scrollY))).toBe(navigationScrollY);
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
            <section class="qris-dialog payment-success-dialog" role="dialog" aria-modal="true" aria-labelledby="aksaPaymentSuccessTitle">
                <button type="button" data-payment-success-close data-payment-success-stage="close" aria-label="Close payment success">Close</button>
                <div class="payment-success-mark" data-payment-success-stage="mark" aria-hidden="true">
                    <svg class="payment-success-check" viewBox="0 0 32 32"><path d="M7 17l6 6L25 10" pathLength="1"></path></svg>
                </div>
                <h2 id="aksaPaymentSuccessTitle" data-payment-success-stage="title">Payment Successful</h2>
                <p id="aksaPaymentSuccessMessage" data-payment-success-stage="message"></p>
                <p id="aksaPaymentSuccessCopyStatus" data-payment-success-stage="status"></p>
                <p id="aksaPaymentSuccessCountdown" data-payment-success-stage="countdown"></p>
                <a href="/licenses" id="aksaPaymentSuccessPrimary" data-payment-success-stage="action">View License</a>
            </section>`;
        document.body.appendChild(modal);

        opener.focus();
        window.showAksaPaymentSuccess({ redirectDelay: 60000 });
    });

    const close = page.getByRole('button', { name: 'Close payment success' });
    const primary = page.getByRole('link', { name: 'View License' });
    await expect(page.locator('.payment-success-mark')).toHaveClass(/is-animating/);
    await expect(page.locator('.payment-success-dialog')).toHaveClass(/is-celebrating/);
    const paymentSequence = await page.evaluate(() => ({
        title: getComputedStyle(document.querySelector('[data-payment-success-stage="title"]')).animationDelay,
        message: getComputedStyle(document.querySelector('[data-payment-success-stage="message"]')).animationDelay,
        action: getComputedStyle(document.querySelector('[data-payment-success-stage="action"]')).animationDelay,
    }));
    expect(paymentSequence).toEqual({ title: '0.26s', message: '0.36s', action: '0.62s' });
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

test('live stock changes animate the updated availability without replacing the card', async ({ page }) => {
    await page.goto('/');

    const firstCard = page.locator('[data-product-stock-card]').first();
    const product = await firstCard.evaluate(card => ({
        id: Number(card.dataset.productId),
        stock: Number(card.dataset.productStock),
        name: card.querySelector('h2')?.textContent?.trim() || '',
    }));
    const nextStock = product.stock + 1;

    await page.evaluate((productId) => {
        window.__stockAnimationObserved = false;
        const selector = `[data-product-stock-card][data-product-id="${productId}"] [data-product-stock-label]`;
        new MutationObserver(() => {
            if (document.querySelector(selector)?.classList.contains('product-stock-changed')) {
                window.__stockAnimationObserved = true;
            }
        }).observe(document.body, {
            attributes: true,
            attributeFilter: ['class'],
            childList: true,
            subtree: true,
        });
    }, product.id);

    await page.route('**/api/product-stocks', route => route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
            products: [{
                id: product.id,
                status: 'ready',
                status_label: 'Ready',
                available_stock: nextStock,
            }],
            total_available_stock: nextStock,
        }),
    }));

    const stockResponse = page.waitForResponse(response => response.url().endsWith('/api/product-stocks'));
    await page.getByRole('textbox', { name: 'Search products' }).fill(product.name);
    await stockResponse;

    const refreshedCard = page.locator(`[data-product-stock-card][data-product-id="${product.id}"]`);
    await expect(refreshedCard).toHaveCount(1);
    await expect(refreshedCard.locator('[data-product-stock-label]')).toContainText(`${nextStock} available`);
    await expect.poll(() => page.evaluate(() => window.__stockAnimationObserved)).toBe(true);
});

test('slow product search uses a stable skeleton without replaying result animations', async ({ page }) => {
    await page.route('**/products-fragment?*', async route => {
        const requestUrl = new URL(route.request().url());
        const responseDelay = requestUrl.searchParams.get('search') ? 1500 : 720;
        await new Promise(resolve => setTimeout(resolve, responseDelay));
        await route.fulfill({
            contentType: 'text/html',
            body: '<article data-product-stock-card data-product-id="1">Aurora</article><article data-product-stock-card data-product-id="2">Fluorite</article>',
        });
    });
    await page.goto('/');

    const categoryResponse = page.waitForResponse(response => (
        response.url().includes('/products-fragment?') && response.ok()
    ));
    await page.getByRole('button', { name: 'PC', exact: true }).click();
    await page.waitForTimeout(360);
    await expect(page.locator('#productContainer > .skeleton-card')).toHaveCount(0);
    await expect(page.locator('[data-product-stock-card]').first()).toBeVisible();
    await categoryResponse;

    await page.getByRole('textbox', { name: 'Search products' }).fill('Aurora');
    await expect(page.locator('#productContainer')).toHaveClass(/product-container-loading/);
    await expect(page.locator('#productContainer > .skeleton-card')).toHaveCount(6);
    await expect(page.locator('[data-product-stock-card]')).toHaveCount(2);
    await expect(page.locator('#productContainer')).not.toHaveClass(
        /product-container-loading|product-filter-leaving|product-filter-entering|product-skeleton-resolved/,
    );
    await expect(page.locator('#productContainer > *').nth(1)).not.toHaveAttribute('style', /product-result-delay/);
});

test('live values roll while the navbar logo keeps a subtle themed hover', async ({ page }) => {
    await page.goto('/');
    const valueRollState = await page.evaluate(() => {
        const value = document.createElement('span');
        const checkoutForm = document.createElement('form');
        const motionPanel = document.createElement('div');
        const summaryPanel = document.createElement('aside');
        const optionsPanel = document.createElement('div');
        value.dataset.valueRollTest = '';
        value.textContent = '3';
        checkoutForm.id = 'checkoutForm';
        motionPanel.dataset.fadeLayerTest = '';
        motionPanel.className = 'product-section checkout-payment-section';
        motionPanel.innerHTML = '<label><input type="radio" name="payment_method" value="test">Test payment</label>';
        summaryPanel.id = 'checkoutFinalSummary';
        summaryPanel.className = 'product-section checkout-final-summary';
        optionsPanel.dataset.checkoutOptionsTest = '';
        optionsPanel.className = 'checkout-options-reveal';
        optionsPanel.setAttribute('aria-hidden', 'true');
        optionsPanel.inert = true;
        optionsPanel.innerHTML = `
            <div class="checkout-options-reveal-inner">
                <div><label class="crypto-coin-option">USDT</label><label class="crypto-coin-option">USDC</label></div>
                <div class="checkout-network-reveal" data-checkout-network-test aria-hidden="true" inert>
                    <div class="checkout-network-reveal-inner">
                        <div><label class="crypto-coin-option" data-checkout-crypto-network-option>BNB Smart Chain</label></div>
                    </div>
                </div>
            </div>
        `;
        checkoutForm.append(motionPanel, summaryPanel, optionsPanel);
        document.querySelector('main')?.append(value, checkoutForm);
        window.animateAksaValue(value, '8');

        return {
            started: value.classList.contains('aksa-value-change'),
            previousContent: getComputedStyle(value, '::before').content,
        };
    });

    const value = page.locator('[data-value-roll-test]');
    await expect(value).toHaveText('8');
    await expect(value).toHaveAttribute('data-aksa-previous-value', '3');
    expect(valueRollState).toEqual({ started: true, previousContent: '"3"' });

    const brand = page.locator('.site-brand-link');
    const logo = page.locator('[data-site-brand-logo]');
    const brandWidth = await brand.evaluate(element => element.getBoundingClientRect().width);
    const logoWidth = await logo.evaluate(element => element.getBoundingClientRect().width);
    expect(Math.abs(brandWidth - logoWidth)).toBeLessThanOrEqual(1);
    expect(await brand.evaluate(element => getComputedStyle(element, '::before').content)).toBe('none');
    await brand.hover();
    await expect(logo).not.toHaveCSS('transform', 'none');
    expect(await logo.evaluate(element => getComputedStyle(element).filter)).toContain('drop-shadow');
    await expect(page.locator('[data-fade-layer-test]')).toHaveCSS('will-change', 'auto');
    await expect(page.locator('[data-fade-layer-test]')).toHaveCSS('backdrop-filter', 'none');
    await page.getByRole('radio', { name: 'Test payment' }).click();
    await expect(page.locator('[data-fade-layer-test]')).not.toHaveClass(/checkout-step-transition/);
    await expect(page.locator('[data-fade-layer-test]')).toHaveCSS('opacity', '1');
    await expect(page.locator('#checkoutFinalSummary')).not.toHaveClass(/checkout-step-transition/);
    await expect(page.locator('#checkoutFinalSummary')).toHaveCSS('opacity', '1');

    const optionsPanel = page.locator('[data-checkout-options-test]');
    await expect(optionsPanel).toHaveCSS('opacity', '0');
    await optionsPanel.evaluate(element => {
        element.classList.add('is-open');
        element.setAttribute('aria-hidden', 'false');
        element.inert = false;
    });
    await expect(optionsPanel).toHaveCSS('opacity', '1');
    await expect(optionsPanel).toHaveAttribute('aria-hidden', 'false');
    await expect(optionsPanel.locator('.crypto-coin-option').first()).toHaveCSS('opacity', '1');

    const networkPanel = page.locator('[data-checkout-network-test]');
    await expect(networkPanel).toHaveCSS('opacity', '0');
    await networkPanel.evaluate(element => {
        element.classList.add('is-open');
        element.setAttribute('aria-hidden', 'false');
        element.inert = false;
    });
    await expect(networkPanel).toHaveCSS('opacity', '1');
    await expect(networkPanel).toHaveAttribute('aria-hidden', 'false');
    await expect(networkPanel.locator('[data-checkout-crypto-network-option]')).toHaveCSS('opacity', '1');
});

test('empty product search can clear all filters', async ({ page }) => {
    await page.setViewportSize({ width: 2048, height: 1100 });
    await page.goto('/');

    const search = page.getByRole('textbox', { name: 'Search products' });
    await search.fill('product-that-does-not-exist-anywhere');
    const emptyState = page.locator('#productContainer > .empty-state');
    await expect(emptyState.getByText('No products found')).toBeVisible();
    await expect(page.locator('#productContainer')).not.toHaveClass(/product-filter-entering/);

    const emptyStateAlignment = await page.evaluate(() => {
        const container = document.querySelector('#productContainer');
        const empty = container?.querySelector(':scope > .empty-state');
        const containerRect = container?.getBoundingClientRect();
        const emptyRect = empty?.getBoundingClientRect();

        return {
            left: Math.abs((containerRect?.left || 0) - (emptyRect?.left || 0)),
            right: Math.abs((containerRect?.right || 0) - (emptyRect?.right || 0)),
        };
    });

    expect(emptyStateAlignment.left).toBeLessThanOrEqual(1);
    expect(emptyStateAlignment.right).toBeLessThanOrEqual(1);

    await page.getByRole('button', { name: 'Clear Filters' }).click();

    await expect(emptyState).toHaveClass(/empty-state-clearing/);

    await expect(search).toHaveValue('');
    await expect(page.locator('#productContainer')).toHaveAttribute('data-product-restore-completed', 'true', {
        timeout: 10000,
    });
    await expect(page.locator('[data-product-stock-card]').first()).toBeVisible({ timeout: 10000 });
    await expect(page.getByRole('button', { name: 'All', exact: true })).toHaveAttribute('aria-pressed', 'true');
});

test('the product search clear control restores results with a staggered entrance', async ({ page }) => {
    await page.route('**/products-fragment?*', async route => {
        const search = new URL(route.request().url()).searchParams.get('search') || '';
        const products = search
            ? '<article data-product-stock-card data-product-id="1">Aurora</article>'
            : '<article data-product-stock-card data-product-id="1">Aurora</article><article data-product-stock-card data-product-id="2">Fluorite</article>';
        await route.fulfill({ contentType: 'text/html', body: products });
    });
    await page.goto('/');

    const search = page.getByRole('textbox', { name: 'Search products' });
    const clear = page.getByRole('button', { name: 'Clear product search' });

    await expect(clear).toBeHidden();
    await search.fill('Aurora');
    await expect(clear).toBeVisible();
    await expect(page.locator('[data-product-stock-card]')).toHaveCount(1);

    await clear.click();
    await expect(search).toHaveValue('');
    await expect(clear).toBeHidden();
    await expect(page.locator('#productContainer')).toHaveAttribute('data-product-restore-completed', 'true');
    await expect(page.locator('#productContainer > *')).toHaveCount(2);
    expect(await page.locator('#productContainer > *').nth(1).evaluate(
        element => element.style.getPropertyValue('--product-result-delay'),
    )).toBe('55ms');
});

test('form validation animates only after an invalid attempt and settles after correction', async ({ page }) => {
    await page.goto('/');

    const validationInput = page.locator('[data-validation-motion-test]');
    await page.evaluate(() => {
        const input = document.createElement('input');
        input.required = true;
        input.dataset.validationMotionTest = 'true';
        document.querySelector('main')?.appendChild(input);
    });

    await expect(validationInput).not.toHaveClass(/aksa-validation-invalid/);
    await validationInput.evaluate(input => input.dispatchEvent(new Event('invalid')));
    await expect(validationInput).toHaveClass(/aksa-validation-invalid/);

    await validationInput.fill('valid');
    await expect(validationInput).toHaveClass(/aksa-validation-corrected/);
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
