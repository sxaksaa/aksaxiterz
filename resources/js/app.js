import './bootstrap';

let appToastTimer = null;

window.showAppToast = function(title, message = '', options = {}) {
    const toast = document.getElementById('appToast');
    const toastTitle = document.getElementById('appToastTitle');
    const toastMessage = document.getElementById('appToastMessage');

    if (!toast || !toastTitle || !toastMessage) return;

    const variant = options.variant || 'info';
    const duration = Number(options.duration || 3400);

    toast.dataset.variant = variant;
    toastTitle.innerText = title;
    toastMessage.innerText = message;
    toast.classList.add('is-visible');

    clearTimeout(appToastTimer);

    if (options.redirectAfter) {
        appToastTimer = setTimeout(() => {
            window.location.href = options.redirectAfter;
        }, options.redirectDelay || 900);
        return;
    }

    appToastTimer = setTimeout(() => {
        toast.classList.remove('is-visible');
    }, duration);
};

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy-license]');

    if (!button) return;

    const key = document.getElementById(`key-${button.dataset.copyLicense}`);
    const text = key?.innerText?.trim();

    if (!text) return;

    const originalText = button.innerText;

    try {
        await navigator.clipboard.writeText(text);
        button.innerText = 'Copied!';
        button.classList.add('text-green-400');
        window.showAppToast?.('License copied', 'The license key is ready to paste.', {
            variant: 'success',
        });
    } catch (error) {
        window.showAppToast?.('Copy failed', 'Select the license key and copy it manually.', {
            variant: 'error',
        });
    } finally {
        setTimeout(() => {
            button.innerText = originalText || 'Copy';
            button.classList.remove('text-green-400');
        }, 1200);
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy-value]');

    if (!button) return;

    const text = button.dataset.copyValue?.trim();

    if (!text) return;

    const originalText = button.innerText;

    try {
        await navigator.clipboard.writeText(text);
        button.innerText = 'Copied!';
        button.classList.add('text-green-400');
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
        setTimeout(() => {
            button.innerText = originalText || 'Copy';
            button.classList.remove('text-green-400');
        }, 1200);
    }
});
