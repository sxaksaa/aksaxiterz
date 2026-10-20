import { runPhp } from './php-runtime.mjs';
import { randomBytes } from 'node:crypto';

runPhp(['artisan', 'test', '--fail-on-warning', ...process.argv.slice(2)], {
    env: {
        ...process.env,
        APP_ENV: 'testing',
        APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
        APP_CONFIG_CACHE: 'bootstrap/cache/testing-config.php',
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: ':memory:',
        DB_URL: '',
    },
});
