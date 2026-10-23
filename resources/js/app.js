import { initializeGlobalPageEnhancements } from './features/page.js';
import { registerNotifications } from './features/notifications.js';
import { registerPayments } from './features/payments.js';
import { registerCart } from './features/cart.js';
import { registerHttp } from './features/http.js';
import { registerCurrency } from './features/currency.js';
import { registerForms } from './features/forms.js';
import { registerMotion } from './features/motion.js';
import { registerEvents } from './features/events.js';
import { registerLicenses } from './features/licenses.js';
import { registerDownloads } from './features/downloads.js';
import { registerOrders } from './features/orders.js';
import { registerCatalog } from './features/catalog.js';

registerNotifications();
registerPayments();
registerCart();
registerHttp();
registerCurrency();
registerForms();
registerMotion();
registerEvents();
registerLicenses();
registerDownloads();
registerOrders();
registerCatalog();

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initializeGlobalPageEnhancements());
} else {
    initializeGlobalPageEnhancements();
}
