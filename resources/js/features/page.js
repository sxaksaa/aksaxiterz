import { initializeOptionalFeatures } from './optional-features.js';
import { initializeDisplayCurrency } from './currency.js';
import { initializeRecentPurchaseToast } from './notifications.js';
import { initializeCustomSelects, initializeLicenseResetSuccess } from './forms.js';
import { updateNavGlider } from './navigation.js';
import { beginHomeStagedReveal, initializeMotionEnhancements, initializeDownloadAccordions, updateCategoryFilterGlider } from './motion.js';
import { initializeSiteIntro } from './site-intro.js';

function initializeGlobalPageEnhancements(root = document) {
    const siteIntroPlaying = root === document && initializeSiteIntro();
    void initializeOptionalFeatures(root).catch(() => { window.showAppToast?.('Checkout unavailable', 'Reload the page and try again.', { variant: 'error' }); });
    initializeDisplayCurrency(root);
    initializeCustomSelects(root);
    initializeRecentPurchaseToast(root);
    initializeDownloadAccordions(root);
    initializeLicenseResetSuccess(root);
    initializeMotionEnhancements(root);
    if (!siteIntroPlaying) beginHomeStagedReveal(root);
    updateNavGlider();
    updateCategoryFilterGlider();

    const pageContent = root === document
        ? document.querySelector('[data-aksa-page-content]')
        : root.closest?.('[data-aksa-page-content]') || root.querySelector?.('[data-aksa-page-content]');
    if (pageContent && pageContent.dataset.pageEntered !== 'true') {
        pageContent.dataset.pageEntered = 'true';

        if (!siteIntroPlaying && !pageContent.hasAttribute('data-aksa-home-content')) {
            pageContent.classList.add('aksa-page-entered');
            window.setTimeout(() => pageContent.classList.remove('aksa-page-entered'), 600);
        }
    }
}

export { initializeGlobalPageEnhancements };
