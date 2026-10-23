import { shouldReduceMotion } from './motion-preferences.js';

export function registerOrders() {
    window.filterAksaOrderEntries = function(root, filter = 'needs-payment', { animate = false } = {}) {
        if (!(root instanceof Element)) return;

        const applyEntries = () => {
            let visibleCount = 0;
            const visibleEntries = [];

            root.querySelectorAll('[data-order-entry]').forEach(entry => {
                const status = entry.dataset.orderStatus;
                const visible = filter === 'all' || status === filter;

                entry.classList.toggle('hidden', !visible);
                entry.classList.remove('filter-result-enter');
                if (visible) {
                    visibleCount += 1;
                    visibleEntries.push(entry);
                }
            });

            root.querySelector('[data-order-filter-empty]')?.classList.toggle('hidden', visibleCount > 0);
            root.classList.remove('filter-results-leaving');

            if (animate) {
                visibleEntries.forEach((entry, index) => {
                    entry.style.setProperty('--filter-result-delay', `${Math.min(index * 55, 330)}ms`);
                    entry.classList.add('filter-result-enter');
                });
                window.setTimeout(() => visibleEntries.forEach(entry => {
                    entry.classList.remove('filter-result-enter');
                    entry.style.removeProperty('--filter-result-delay');
                }), 900);
            }
        };

        window.clearTimeout(root._orderFilterTimer);
        if (animate && !shouldReduceMotion()) {
            root.classList.add('filter-results-leaving');
            root._orderFilterTimer = window.setTimeout(applyEntries, 140);
        } else {
            applyEntries();
        }
    };
}
