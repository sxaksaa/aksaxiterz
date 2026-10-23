let pendingModule = null;

export function loadPayments() {
    if (!pendingModule) {
        pendingModule = import('./payments.js').then(module => {
            module.registerPayments();
        }).catch(error => {
            pendingModule = null;
            throw error;
        });
    }
    return pendingModule;
}

export function registerPaymentApi() {
    for (const name of [
        'renderAksaQrCode', 'renderAksaStyledQrCode', 'syncAksaGopayQrisOrder',
        'syncAksaCryptoOrder', 'syncAksaBinancePayOrder', 'openAksaQrisModal',
        'closeAksaQrisModal', 'openAksaCryptoModal', 'closeAksaCryptoModal',
        'openAksaBinancePayModal', 'closeAksaBinancePayModal',
        'showAksaPaymentSuccess', 'closeAksaPaymentSuccessModal',
    ]) {
        window[name] = async (...args) => {
            await loadPayments();
            return window[name](...args);
        };
    }
}
