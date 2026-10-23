import { setButtonLabel } from './ui.js';
import { shouldReduceMotion } from './motion-preferences.js';
import { initializeMotionEnhancements } from './motion.js';

const invalidFormControls = new WeakSet();

const validationAnimationTimers = new WeakMap();

function formValidationTarget(control) {
    if (!(control instanceof HTMLElement)) return null;

    if (control.matches('select[data-aksa-select-enhanced]')) {
        const customSelect = control.nextElementSibling;

        if (customSelect?.classList.contains('aksa-select')) return customSelect;
    }

    return control;
}

function animateFormValidation(control, className, duration = 680) {
    const target = formValidationTarget(control);

    if (!target) return;

    window.clearTimeout(validationAnimationTimers.get(target));
    target.classList.remove('aksa-validation-invalid', 'aksa-validation-corrected');
    void target.offsetWidth;
    target.classList.add(className);
    validationAnimationTimers.set(target, window.setTimeout(() => {
        target.classList.remove(className);
        validationAnimationTimers.delete(target);
    }, shouldReduceMotion() ? 0 : duration));
}

function handleCorrectedFormControl(event) {
    const control = event.target;

    if (!(control instanceof HTMLInputElement)
        && !(control instanceof HTMLSelectElement)
        && !(control instanceof HTMLTextAreaElement)
        || !invalidFormControls.has(control)
        || !control.validity.valid) return;

    invalidFormControls.delete(control);
    animateFormValidation(control, 'aksa-validation-corrected', 520);
}

const customSelects = new WeakMap();

function customSelectOptionLabel(option) {
    return (option?.textContent || option?.label || option?.value || 'Select').trim();
}

function selectedCustomSelectOption(select) {
    return select.selectedOptions?.[0] || select.options?.[select.selectedIndex] || select.querySelector('option');
}

function customSelectVisibleOptions(select) {
    return Array.from(select.options || []).filter((option) => !option.hidden);
}

function closeCustomSelect(select) {
    const state = customSelects.get(select);

    if (!state) return;

    state.wrapper.classList.remove('is-open');
    state.panel.classList.add('hidden');
    state.trigger.setAttribute('aria-expanded', 'false');

    if (state.section) {
        state.section.style.zIndex = state.previousSectionZIndex || '';
        state.section.dataset.aksaSelectOpen = 'false';
    }

    if (state.overflowSurface) {
        state.overflowSurface.classList.remove('is-select-open');
        state.overflowSurface.style.zIndex = state.previousOverflowSurfaceZIndex || '';
    }
}

function closeOtherCustomSelects(currentSelect = null) {
    document.querySelectorAll('select[data-aksa-select-enhanced]').forEach((select) => {
        if (select !== currentSelect) {
            closeCustomSelect(select);
        }
    });
}

function refreshCustomSelect(select) {
    const state = customSelects.get(select);

    if (!state) return;

    const selectedOption = selectedCustomSelectOption(select);

    state.label.textContent = customSelectOptionLabel(selectedOption);
    state.trigger.disabled = select.disabled;
    state.panel.innerHTML = '';

    const visibleOptions = customSelectVisibleOptions(select);

    if (visibleOptions.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'aksa-select-option is-disabled';
        empty.textContent = 'No options available';
        state.panel.appendChild(empty);
        return;
    }

    visibleOptions.forEach((option) => {
        const button = document.createElement('button');
        const check = document.createElement('span');
        const isSelected = option.value === select.value;

        button.type = 'button';
        button.className = 'aksa-select-option';
        button.disabled = option.disabled;
        button.dataset.value = option.value;
        button.setAttribute('role', 'option');
        button.setAttribute('aria-selected', String(isSelected));

        if (isSelected) {
            button.classList.add('is-active');
        }

        if (option.disabled) {
            button.classList.add('is-disabled');
        }

        button.append(document.createTextNode(customSelectOptionLabel(option)));
        check.className = 'aksa-select-option-check';
        button.appendChild(check);
        button.addEventListener('click', () => {
            if (option.disabled) return;

            select.value = option.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            refreshCustomSelect(select);
            closeCustomSelect(select);
        });
        state.panel.appendChild(button);
    });
}

function enhanceCustomSelect(select) {
    if (select.dataset.aksaSelectEnhanced || select.multiple) return;

    const wrapper = document.createElement('div');
    const trigger = document.createElement('button');
    const label = document.createElement('span');
    const chevron = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    const chevronPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    const panel = document.createElement('div');
    const section = select.closest('.product-section, .license-toolbar');
    const overflowSurface = select.closest('.license-card, .order-mobile-card');

    select.dataset.aksaSelectEnhanced = 'true';
    select.tabIndex = -1;

    wrapper.className = 'aksa-select';
    trigger.type = 'button';
    trigger.className = 'aksa-select-trigger';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    label.className = 'aksa-select-label';
    chevron.classList.add('aksa-select-chevron');
    chevron.setAttribute('viewBox', '0 0 24 24');
    chevron.setAttribute('fill', 'none');
    chevron.setAttribute('stroke', 'currentColor');
    chevron.setAttribute('stroke-width', '1.8');
    chevron.setAttribute('aria-hidden', 'true');
    chevronPath.setAttribute('stroke-linecap', 'round');
    chevronPath.setAttribute('stroke-linejoin', 'round');
    chevronPath.setAttribute('d', 'm6 9 6 6 6-6');
    chevron.appendChild(chevronPath);
    panel.className = 'aksa-select-panel hidden';
    panel.setAttribute('role', 'listbox');
    trigger.append(label, chevron);
    wrapper.append(trigger, panel);
    select.insertAdjacentElement('afterend', wrapper);

    customSelects.set(select, {
        wrapper,
        trigger,
        label,
        panel,
        section,
        previousSectionZIndex: section?.style.zIndex || '',
        overflowSurface,
        previousOverflowSurfaceZIndex: overflowSurface?.style.zIndex || '',
    });

    trigger.addEventListener('click', (event) => {
        event.stopPropagation();

        if (select.disabled) return;

        const state = customSelects.get(select);
        const isOpen = state.wrapper.classList.contains('is-open');

        closeOtherCustomSelects(select);
        state.wrapper.classList.toggle('is-open', !isOpen);
        state.panel.classList.toggle('hidden', isOpen);
        state.trigger.setAttribute('aria-expanded', String(!isOpen));

        if (state.section) {
            state.section.style.position = 'relative';
            state.section.style.zIndex = isOpen ? state.previousSectionZIndex : '80';
            state.section.dataset.aksaSelectOpen = String(!isOpen);
        }

        if (state.overflowSurface) {
            state.overflowSurface.classList.toggle('is-select-open', !isOpen);
            state.overflowSurface.style.zIndex = isOpen ? state.previousOverflowSurfaceZIndex : '90';
        }
    });

    select.addEventListener('change', () => refreshCustomSelect(select));
    select.addEventListener('aksa-select-refresh', () => refreshCustomSelect(select));

    const observer = new MutationObserver(() => refreshCustomSelect(select));
    observer.observe(select, {
        attributes: true,
        childList: true,
        subtree: true,
        characterData: true,
    });

    refreshCustomSelect(select);
}

function initializeCustomSelects(root = document) {
    root.querySelectorAll('select.search-bar:not([data-native-select])').forEach(enhanceCustomSelect);
}

function initializeLicenseResetSuccess(root = document) {
    root.querySelectorAll?.('[data-license-reset-success]').forEach(button => {
        if (button.dataset.licenseResetSuccessReady === 'true') return;

        button.dataset.licenseResetSuccessReady = 'true';
        window.setTimeout(() => {
            setButtonLabel(button, button.dataset.resetFinalLabel || 'Reset cooldown active');
            button.classList.remove('is-reset-success');
        }, shouldReduceMotion() ? 0 : 1450);
    });
}

export { closeOtherCustomSelects, initializeCustomSelects, initializeLicenseResetSuccess };

export function registerForms() {
    document.addEventListener('invalid', (event) => {
        const control = event.target;

        if (!(control instanceof HTMLInputElement)
            && !(control instanceof HTMLSelectElement)
            && !(control instanceof HTMLTextAreaElement)) return;

        invalidFormControls.add(control);
        animateFormValidation(control, 'aksa-validation-invalid');
    }, true);

    document.addEventListener('input', handleCorrectedFormControl);

    document.addEventListener('change', handleCorrectedFormControl);

    window.refreshAksaCustomSelects = function() {
        document.querySelectorAll('select[data-aksa-select-enhanced]').forEach((select) => refreshCustomSelect(select));
    };

    window.initializeAksaPageEnhancements = function(root = document) {
        initializeCustomSelects(root);
        initializeLicenseResetSuccess(root);
        initializeMotionEnhancements(root);
    };
}
