import { defineConfig, devices } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const browserSandbox = join(tmpdir(), `aksa-browser-${randomUUID()}`);

export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: false,
    retries: 1,
    reporter: 'line',
    metadata: { browserSandbox },
    globalTeardown: './tests/Browser/teardown.mjs',
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
        command: 'node tests/Browser/server.mjs',
        env: { AKSA_BROWSER_SANDBOX: browserSandbox },
        url: 'http://127.0.0.1:8173/up',
        reuseExistingServer: false,
        timeout: 60_000,
    },
});
