import { rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve, dirname, basename } from 'node:path';

export function validateBrowserSandbox(directory) {
    if (typeof directory !== 'string'
        || dirname(resolve(directory)) !== resolve(tmpdir())
        || !/^aksa-browser-[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/.test(basename(directory))) {
        throw new Error('Refusing to use an unexpected browser-test directory.');
    }
    return resolve(directory);
}

export function removeBrowserSandbox(directory) {
    rmSync(validateBrowserSandbox(directory), { recursive: true, force: true, maxRetries: 3 });
}
