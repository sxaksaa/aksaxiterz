# Frontend structure

`app.js` is the Vite entry point. It registers persistent browser handlers once,
then initializes the current page when the DOM is ready.

Feature code lives in `features/`:

- `checkout.js`: checkout selection, voucher preview, invoice submission and review recovery.
- `cart.js`: mini cart, quantity updates, removal and Undo.
- `navigation.js`: page fetching, history restoration and page script cleanup.
- `events.js`: persistent navigation, keyboard and dashboard interaction handlers.
- `payments.js`: QR rendering, payment modals, status checks and expiry handling.
- `currency.js`: display currency selection and price formatting.
- `motion.js`, `motion-preferences.js`, `site-intro.js`: animation and reduced-motion behavior.
- `forms.js`, `ui.js`, `http.js`: shared form controls, labels and CSRF requests.
- `notifications.js`: app toast and recent purchase feed.
- `licenses.js`, `license-display.js`, `orders.js`, `downloads.js`, `catalog.js`, `dashboard.js`: page features.
- `page.js`: initialization for the initial document.

Import shared functions explicitly. Keep dependencies one-way; do not import
`app.js` from a feature. Existing `window.aksa*` APIs remain for Blade pages that
use them.

`register*()` functions install document/window handlers once. Page initializers
must accept a root and guard against duplicate initialization. Navigation calls
the initializers again after replacing page content. Clean up page-specific
listeners, requests and timers on `aksa:before-page-swap`.

Blade provides checkout data through escaped `data-checkout-config` JSON; it no
longer embeds the checkout implementation. Do not put credentials in this data.

Run `npm run quality` after changing shared initialization or navigation behavior.
