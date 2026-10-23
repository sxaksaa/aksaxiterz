import { setButtonLabel } from './ui.js';
import { shouldReduceMotion } from './motion-preferences.js';

const catalogDisclosureAnimations = new WeakMap();

function animateCatalogDisclosure(element, expanded) {
    const isDetails = element.matches('details');
    const previous = catalogDisclosureAnimations.get(element);
    const currentlyExpanded = isDetails ? element.open : !element.hidden;
    if (!previous && currentlyExpanded === expanded) return;

    const startHeight = element.getBoundingClientRect().height;
    const startOpacity = currentlyExpanded ? getComputedStyle(element).opacity : '0';
    previous?.cancel();
    catalogDisclosureAnimations.delete(element);
    element.style.overflow = '';
    element.dataset.expanded = String(expanded);

    if (isDetails) element.open = expanded;
    else element.hidden = !expanded;
    const endHeight = element.getBoundingClientRect().height;
    if (shouldReduceMotion()) return;

    // Keep the content rendered until the closing animation completes.
    if (isDetails) element.open = true;
    else element.hidden = false;
    element.style.overflow = 'hidden';
    const animation = element.animate([
        { height: `${startHeight}px`, opacity: isDetails ? 1 : startOpacity },
        { height: `${endHeight}px`, opacity: isDetails || expanded ? 1 : 0 },
    ], { duration: 420, easing: 'cubic-bezier(0.4, 0, 0.2, 1)' });
    catalogDisclosureAnimations.set(element, animation);
    animation.onfinish = () => {
        if (catalogDisclosureAnimations.get(element) !== animation) return;
        if (isDetails) element.open = expanded;
        else element.hidden = !expanded;
        element.style.overflow = '';
        catalogDisclosureAnimations.delete(element);
    };
}

export function registerCatalog() {
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-license-show-all]');
        const group = button?.closest('[data-license-group]');

        if (!button || !group) return;

        const expanded = button.getAttribute('aria-expanded') === 'true';
        group.querySelectorAll('[data-license-extra]').forEach(row => row.classList.toggle('hidden', expanded));
        button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        setButtonLabel(button, expanded ? button.dataset.collapsedLabel : 'Show less');
        button.querySelector('[data-show-all-chevron]')?.classList.toggle('rotate-180', !expanded);
    });

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-catalog-edit-button]');
        if (!button) return;

        const form = button.closest('[data-catalog-edit-form]');
        const fields = form.querySelector('[data-catalog-fields]');
        if (fields.disabled) {
            fields.disabled = false;
            button.textContent = 'Save';
            fields.querySelector('input:not([type="hidden"])')?.focus();
            return;
        }

        form.requestSubmit();
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!form.matches('[data-catalog-edit-form]')) return;
        if (form.querySelector('[data-catalog-fields]').disabled) {
            event.preventDefault();
        }
    });

    document.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-catalog-panel-toggle]');
        if (!toggle) return;

        const opening = toggle.getAttribute('aria-expanded') !== 'true';
        document.querySelectorAll('[data-catalog-panel-toggle]').forEach((button) => {
            const panel = document.getElementById(button.getAttribute('aria-controls'));
            const expanded = button === toggle && opening;
            button.setAttribute('aria-expanded', String(expanded));
            if (panel) animateCatalogDisclosure(panel, expanded);
        });
    });

    document.addEventListener('click', (event) => {
        const summary = event.target.closest('[data-catalog-accordion] > summary');
        if (!summary) return;
        event.preventDefault();
        const details = summary.parentElement;
        const expanded = details.dataset.expanded === undefined ? details.open : details.dataset.expanded === 'true';
        animateCatalogDisclosure(details, !expanded);
    });
}
