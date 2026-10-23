function setButtonLabel(button, label) {
    const labelTarget = button?.querySelector('[data-button-label]');

    if (labelTarget) {
        labelTarget.textContent = label;
        return;
    }

    if (button) {
        button.innerText = label;
    }
}

function getButtonLabel(button) {
    return button?.querySelector('[data-button-label]')?.textContent || button?.innerText || '';
}

function formatIdr(amount) {
    return `Rp ${Number(amount || 0).toLocaleString('id-ID')}`;
}

export { setButtonLabel, getButtonLabel, formatIdr };
