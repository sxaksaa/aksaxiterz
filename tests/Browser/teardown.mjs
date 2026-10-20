import { removeBrowserSandbox } from '../../scripts/browser-sandbox.mjs';

export default async function teardown(config) {
    // Playwright force-terminates server processes on Windows; clean up in the runner too.
    removeBrowserSandbox(config.metadata.browserSandbox);
}
