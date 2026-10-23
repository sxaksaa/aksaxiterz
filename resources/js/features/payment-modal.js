import { shouldReduceMotion } from './motion-preferences.js';

const modalReturnFocus = new WeakMap();

const modalCloseTimers = new WeakMap();

const MODAL_TRANSITION_DURATION = 380;

function modalFocusableElements(modal) {
    return [...modal.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )].filter((element) => (
        !element.hidden &&
        element.getAttribute('aria-hidden') !== 'true' &&
        element.getClientRects().length > 0
    ));
}

function openAccessibleModal(modal) {
    if (!(modal instanceof HTMLElement)) return;

    if (document.activeElement instanceof HTMLElement && !modal.contains(document.activeElement)) {
        modalReturnFocus.set(modal, document.activeElement);
    }

    window.clearTimeout(modalCloseTimers.get(modal));
    modalCloseTimers.delete(modal);
    modal.classList.remove('hidden', 'is-closing');
    modal.removeAttribute('inert');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('overflow-hidden');

    window.requestAnimationFrame(() => {
        modal.classList.add('is-open');

        window.requestAnimationFrame(() => {
            modalFocusableElements(modal)[0]?.focus();
        });
    });
}

function closeAccessibleModal(modal) {
    if (!(modal instanceof HTMLElement) || modal.classList.contains('hidden') || modal.classList.contains('is-closing')) return;

    modal.classList.remove('is-open');
    modal.classList.add('is-closing');
    modal.setAttribute('inert', '');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('overflow-hidden');

    const returnTarget = modalReturnFocus.get(modal);
    modalReturnFocus.delete(modal);
    if (returnTarget?.isConnected) returnTarget.focus();

    const finishClosing = () => {
        modal.classList.add('hidden');
        modal.classList.remove('is-closing');
        modalCloseTimers.delete(modal);
    };

    if (shouldReduceMotion()) {
        finishClosing();
        return;
    }

    modalCloseTimers.set(modal, window.setTimeout(finishClosing, MODAL_TRANSITION_DURATION));
}

function activePaymentModal() {
    return document.querySelector('.qris-modal:not(.hidden)[aria-hidden="false"]');
}

function closePaymentModal(modal) {
    const closeById = {
        aksaQrisModal: window.closeAksaQrisModal,
        aksaCryptoModal: window.closeAksaCryptoModal,
        aksaBinancePayModal: window.closeAksaBinancePayModal,
        aksaPaymentSuccessModal: window.closeAksaPaymentSuccessModal,
    };

    closeById[modal?.id]?.();
}

export { modalFocusableElements, openAccessibleModal, closeAccessibleModal, activePaymentModal, closePaymentModal };
