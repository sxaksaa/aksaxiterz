import { setButtonLabel, getButtonLabel } from './ui.js';
import { shouldReduceMotion } from './motion-preferences.js';
import { renderAnimatedLicenseKey } from './license-display.js';

function filterLicenseGroups() {
    const search = String(document.getElementById('licenseSearch')?.value || '').trim().toLowerCase();
    const product = String(document.getElementById('licenseProductFilter')?.value || '');
    const root = document.getElementById('licenseGroups');

    if (!root) return;

    window.clearTimeout(root._licenseFilterTimer);
    root.classList.add('filter-results-leaving');
    root._licenseFilterTimer = window.setTimeout(() => {
        const visibleGroups = [];

        root.querySelectorAll('[data-license-group]').forEach(group => {
            const matchesSearch = !search || String(group.dataset.licenseSearch || '').includes(search);
            const matchesProduct = !product || group.dataset.licenseProduct === product;
            const visible = matchesSearch && matchesProduct;

            group.classList.toggle('hidden', !visible);
            group.classList.remove('filter-result-enter');
            if (visible) visibleGroups.push(group);
        });

        root.classList.remove('filter-results-leaving');
        visibleGroups.forEach((group, index) => {
            group.style.setProperty('--filter-result-delay', `${Math.min(index * 70, 350)}ms`);
            group.classList.add('filter-result-enter');
        });

        window.setTimeout(() => visibleGroups.forEach(group => {
            group.classList.remove('filter-result-enter');
            group.style.removeProperty('--filter-result-delay');
        }), 900);
    }, shouldReduceMotion() ? 0 : 140);
}

export { filterLicenseGroups };

export function registerLicenses() {
    document.addEventListener('input', (event) => {
        if (event.target.matches('#licenseSearch')) filterLicenseGroups();
    });

    document.addEventListener('change', (event) => {
        if (event.target.matches('#licenseProductFilter')) filterLicenseGroups();
    });

    document.addEventListener('click', async (event) => {
        const revealButton = event.target.closest('[data-reveal-license]');

        if (revealButton) {
            const key = document.getElementById(`key-${revealButton.dataset.revealLicense}`);
            const fullValue = key?.dataset.licenseKeyValue || '';
            const masked = key?.dataset.licenseMasked === 'true';

            if (key && fullValue) {
                clearTimeout(revealButton._licenseHideTimeout);
                renderAnimatedLicenseKey(key, fullValue, masked);
                key.dataset.licenseMasked = masked ? 'false' : 'true';
                revealButton.setAttribute('aria-pressed', masked ? 'true' : 'false');
                setButtonLabel(revealButton, masked ? 'Hide' : 'Reveal');

                if (masked) {
                    revealButton._licenseHideTimeout = setTimeout(() => {
                        const latestValue = key.dataset.licenseKeyValue || '';
                        renderAnimatedLicenseKey(key, latestValue, false);
                        key.dataset.licenseMasked = 'true';
                        revealButton.setAttribute('aria-pressed', 'false');
                        setButtonLabel(revealButton, 'Reveal');
                    }, 30000);
                }
            }
            return;
        }

        const button = event.target.closest('[data-copy-license]');

        if (!button) return;

        const key = document.getElementById(`key-${button.dataset.copyLicense}`);
        const text = key?.dataset.licenseKeyValue?.trim();

        if (!text) return;

        const originalText = getButtonLabel(button);

        try {
            await navigator.clipboard.writeText(text);
            setButtonLabel(button, 'Copied ✓');
            button.classList.remove('text-green-400');
            button.classList.add('text-aksa-accent-soft');
            button.classList.add('is-copy-success');
            const licenseBox = button.closest('.license-key-box');
            licenseBox?.classList.remove('license-copy-success');
            if (licenseBox) void licenseBox.offsetWidth;
            licenseBox?.classList.add('license-copy-success');
            window.showAppToast?.('License copied', 'The license key is ready to paste.', {
                variant: 'success',
            });
        } catch (error) {
            window.showAppToast?.('Copy failed', 'Select the license key and copy it manually.', {
                variant: 'error',
            });
        } finally {
            setTimeout(() => {
                setButtonLabel(button, originalText || 'Copy');
                button.classList.remove('text-aksa-accent-soft');
                button.classList.remove('is-copy-success');
                button.closest('.license-key-box')?.classList.remove('license-copy-success');
            }, 1800);
        }
    });

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-copy-value]');

        if (!button) return;

        const text = button.dataset.copyValue?.trim();

        if (!text) return;

        const originalText = getButtonLabel(button);
        const copyAllGroup = button.matches('[data-copy-all-licenses]')
            ? button.closest('[data-license-group]')
            : null;

        try {
            await navigator.clipboard.writeText(text);
            setButtonLabel(button, button.dataset.copySuccessLabel || 'Copied');
            button.classList.remove('text-green-400');
            button.classList.add('text-aksa-accent-soft');
            button.classList.toggle('is-copy-all-success', Boolean(copyAllGroup));
            copyAllGroup?.classList.remove('license-group-copy-success');
            if (copyAllGroup) void copyAllGroup.offsetWidth;
            copyAllGroup?.classList.add('license-group-copy-success');
            window.showAppToast?.(
                button.dataset.copyTitle || 'Copied',
                button.dataset.copyMessage || 'The text is ready to paste.', {
                    variant: 'success',
                }
            );
        } catch (error) {
            window.showAppToast?.('Copy failed', 'Select the text and copy it manually.', {
                variant: 'error',
            });
        } finally {
            window.clearTimeout(button._copyValueRestoreTimer);
            button._copyValueRestoreTimer = setTimeout(() => {
                setButtonLabel(button, originalText || 'Copy');
                button.classList.remove('text-aksa-accent-soft');
                button.classList.remove('is-copy-all-success');
                copyAllGroup?.classList.remove('license-group-copy-success');
            }, 1800);
        }
    });
}
