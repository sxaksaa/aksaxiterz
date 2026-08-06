import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: false,
    retries: 1,
    reporter: 'line',
    use: {
        baseURL: 'http://127.0.0.1:8173',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
        { name: 'mobile-chromium', use: { ...devices['Pixel 7'] } },
    ],
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8173',
        url: 'http://127.0.0.1:8173/up',
        reuseExistingServer: false,
        timeout: 30_000,
    },
});
