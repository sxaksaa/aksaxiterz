export async function initializeOptionalFeatures(root = document) {
    const form = root.matches?.('#checkoutForm') ? root : root.querySelector('#checkoutForm');
    if (!form) return;
    const { initializeCheckout } = await import('./checkout.js');
    // A navigation may have replaced the form while its chunk was downloading.
    if (form.isConnected) initializeCheckout(form);
}
