import { updateNavGlider } from './navigation.js';
import { beginHomeStagedReveal, updateCategoryFilterGlider } from './motion.js';

const SITE_INTRO_CLASS_NAMES = [
    'aksa-intro-pending',
    'aksa-intro-running',
    'aksa-intro-mark-ready',
    'aksa-intro-brand-ready',
    'aksa-intro-docking',
    'aksa-intro-nav-ready',
    'aksa-intro-logo-handoff',
    'aksa-intro-content-ready',
    'aksa-intro-revealing',
];

function finishSiteIntro(root, intro, timers, state = 'complete') {
    timers.forEach(timer => window.clearTimeout(timer));
    window.clearTimeout(window.__aksaIntroFailsafe);
    SITE_INTRO_CLASS_NAMES.forEach(className => root.classList.remove(className));
    root.dataset.aksaIntroState = state;
    intro?.remove();
    window.requestAnimationFrame(() => {
        updateNavGlider();
        updateCategoryFilterGlider();
    });
}

function initializeSiteIntro() {
    const root = document.documentElement;
    const intro = document.getElementById('aksaSiteIntro');

    if (!intro) return false;

    if (root.dataset.aksaIntroState !== 'pending') {
        intro.remove();
        return false;
    }

    const lockup = intro.querySelector('[data-site-intro-lockup]');
    const brandLogo = document.querySelector('[data-site-brand-logo]');
    const timers = [];

    if (!lockup || !brandLogo) {
        finishSiteIntro(root, intro, timers, 'recovered');
        return false;
    }

    window.clearTimeout(window.__aksaIntroFailsafe);
    root.dataset.aksaIntroState = 'running';
    root.classList.remove('aksa-intro-pending');
    root.classList.add('aksa-intro-running');

    const schedule = (callback, delay) => {
        const timer = window.setTimeout(callback, delay);
        timers.push(timer);
        return timer;
    };

    window.requestAnimationFrame(() => {
        root.classList.add('aksa-intro-mark-ready');
    });

    schedule(() => root.classList.add('aksa-intro-brand-ready'), 520);

    schedule(() => {
        const navbarPill = brandLogo.closest('.site-navbar-pill');
        const pillRect = navbarPill?.getBoundingClientRect();
        const lockupWidth = lockup.offsetWidth || 1;
        let relativeLeft = 0;
        let relativeTop = 0;
        let currentElement = brandLogo;

        while (currentElement && currentElement !== navbarPill) {
            relativeLeft += currentElement.offsetLeft;
            relativeTop += currentElement.offsetTop;
            currentElement = currentElement.offsetParent;
        }

        const pillLayoutLeft = pillRect
            ? pillRect.left + pillRect.width / 2 - navbarPill.offsetWidth / 2
            : 0;
        const pillLayoutTop = pillRect?.top || 0;
        const targetCenterX = pillLayoutLeft + relativeLeft + brandLogo.offsetWidth / 2;
        const targetCenterY = pillLayoutTop + relativeTop + brandLogo.offsetHeight / 2;
        const scale = Math.max(0.08, brandLogo.offsetWidth / lockupWidth);

        const lockupLayoutCenterX = lockup.offsetLeft;
        const lockupLayoutCenterY = lockup.offsetTop;

        lockup.style.setProperty('--site-intro-dock-x', `${targetCenterX - lockupLayoutCenterX}px`);
        lockup.style.setProperty('--site-intro-dock-y', `${targetCenterY - lockupLayoutCenterY}px`);
        lockup.style.setProperty('--site-intro-dock-scale', String(scale));
        root.classList.add('aksa-intro-docking');
    }, 1100);

    schedule(() => root.classList.add('aksa-intro-nav-ready'), 1320);
    schedule(() => root.classList.add('aksa-intro-logo-handoff'), 2140);
    schedule(() => {
        root.classList.add('aksa-intro-content-ready');
        beginHomeStagedReveal(document);
    }, 2520);
    schedule(() => {
        root.classList.remove('aksa-intro-running');
        root.classList.add('aksa-intro-revealing');
    }, 2600);
    schedule(() => finishSiteIntro(root, intro, timers), 3320);

    return true;
}

export { initializeSiteIntro };
