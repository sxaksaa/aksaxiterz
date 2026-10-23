import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { randomBytes } from 'node:crypto';
import { spawn } from 'node:child_process';
import { phpBinary, runPhp } from '../../scripts/php-runtime.mjs';
import { validateBrowserSandbox, removeBrowserSandbox } from '../../scripts/browser-sandbox.mjs';

const directory = validateBrowserSandbox(process.env.AKSA_BROWSER_SANDBOX);
mkdirSync(directory);
const database = join(directory, 'database.sqlite');
writeFileSync(database, '');
for (const path of ['framework/views', 'framework/sessions', 'framework/cache/data', 'logs']) {
    mkdirSync(join(directory, path), { recursive: true });
}

const env = {
    ...process.env,
    APP_ENV: 'testing',
    APP_DEBUG: 'false',
    APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
    APP_URL: 'http://127.0.0.1:8173',
    APP_CONFIG_CACHE: join(directory, 'config.php'),
    APP_ROUTES_CACHE: join(directory, 'routes.php'),
    APP_EVENTS_CACHE: join(directory, 'events.php'),
    LARAVEL_STORAGE_PATH: directory,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: database,
    DB_URL: '',
    CACHE_STORE: 'array',
    SESSION_DRIVER: 'file',
    SESSION_COOKIE: `aksa_browser_${randomBytes(8).toString('hex')}`,
    SESSION_DOMAIN: 'null',
    SESSION_SECURE_COOKIE: 'false',
    VIEW_COMPILED_PATH: join(directory, 'framework/views'),
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'array',
    GOPAY_QRIS_ENABLED: 'false',
    AKSA_CSP: 'true',
    AKSA_CANONICAL_URL: 'http://127.0.0.1:8173',
    AKSA_ENFORCE_CANONICAL_URL: 'false',
    AKSA_FORCE_HTTPS: 'false',
    AKSA_HSTS: 'false',
};

function cleanup() {
    removeBrowserSandbox(directory);
}

let server;
try {
    runPhp(['artisan', 'migrate', '--seed', '--force', '--no-interaction'], { env });
    runPhp(['tests/Browser/render-checkout.php', directory], { env });
    // Run directly so the process tracked by Playwright owns the actual server.
    server = spawn(phpBinary, ['-S', '127.0.0.1:8173', '../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'], {
        cwd: resolve('public'), env, stdio: 'inherit',
    });
    server.on('error', error => { console.error(error); process.exitCode = 1; });
    server.on('exit', code => { cleanup(); process.exitCode = code ?? 0; });
    for (const signal of ['SIGINT', 'SIGTERM']) {
        process.on(signal, () => server.kill(signal));
    }
} catch (error) {
    cleanup();
    throw error;
}
