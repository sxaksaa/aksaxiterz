import { shouldReduceMotion } from './motion-preferences.js';

function filterDownloads(input) {
    const catalog = input.closest('[data-download-catalog]');
    if (!catalog) return;

    const query = String(input.value || '').trim();
    const search = query.toLowerCase();
    const cards = [...catalog.querySelectorAll('[data-download-search]')];
    let visibleCount = 0;
    cards.forEach(card => {
        const visible = !search || String(card.dataset.downloadSearch || '').includes(search);
        card.classList.toggle('hidden', !visible);
        if (visible) visibleCount++;
    });
    catalog.querySelector('[data-download-empty]')?.classList.toggle('hidden', !search || cards.length === 0 || visibleCount > 0);
    const queryLabel = catalog.querySelector('[data-download-query]');
    if (queryLabel) queryLabel.textContent = query;
}

export function registerDownloads() {
    document.addEventListener('input', (event) => {
        if (event.target.matches('#downloadSearch')) filterDownloads(event.target);
    });

    document.addEventListener('click', (event) => {
        const clearSearch = event.target.closest('[data-clear-download-search]');
        if (clearSearch) {
            const input = clearSearch.closest('[data-download-catalog]')?.querySelector('#downloadSearch');
            if (input) {
                input.value = '';
                filterDownloads(input);
                input.focus();
            }
            return;
        }

        const link = event.target.closest('[data-download-resource]');

        if (!link) return;

        const label = link.querySelector('[data-download-resource-label]');
        const originalLabel = label?.dataset.originalLabel || label?.textContent?.trim() || 'Download';

        if (label) label.dataset.originalLabel = originalLabel;
        clearTimeout(link._downloadLaunchTimer);
        clearTimeout(link._downloadRestoreTimer);
        link.classList.remove('is-download-complete');
        link.classList.add('is-download-launching');
        if (label) label.textContent = 'Opening...';

        link._downloadLaunchTimer = setTimeout(() => {
            link.classList.remove('is-download-launching');
            link.classList.add('is-download-complete');
            if (label) label.textContent = link.dataset.downloadCompleteLabel || 'Opened';

            link._downloadRestoreTimer = setTimeout(() => {
                link.classList.remove('is-download-complete');
                if (label) label.textContent = originalLabel;
            }, 1250);
        }, shouldReduceMotion() ? 0 : 520);
    });
}
