import { shouldReduceMotion } from './motion-preferences.js';

function maskedLicenseValue(value) {
    return value.length > 8
        ? `${value.slice(0, 4)}${'•'.repeat(value.length - 8)}${value.slice(-4)}`
        : '•'.repeat(Math.max(4, value.length));
}

function renderAnimatedLicenseKey(key, value, revealed) {
    const displayValue = revealed ? value : maskedLicenseValue(value);

    key.classList.toggle('is-license-revealed', revealed);
    key.classList.toggle('is-license-masked', !revealed);

    if (shouldReduceMotion()) {
        key.textContent = displayValue;
        return;
    }

    const fragment = document.createDocumentFragment();

    [...displayValue].forEach((character, index) => {
        const span = document.createElement('span');
        span.className = 'license-key-character';
        span.style.setProperty('--license-character-delay', `${Math.min(index * 14, 280)}ms`);
        span.textContent = character;
        fragment.appendChild(span);
    });

    key.replaceChildren(fragment);
}

export { renderAnimatedLicenseKey };
