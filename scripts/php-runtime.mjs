import { existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';

// Prefer the project's PHP 8.3 runtime without changing other projects' PHP.
export const phpBinary = process.env.AKSA_PHP_BINARY
    || (process.platform === 'win32' && existsSync('C:/tools/php83/php.exe')
        ? 'C:/tools/php83/php.exe' : 'php');

export function runPhp(args, options = {}) {
    const result = spawnSync(phpBinary, args, { stdio: 'inherit', ...options });
    if (result.error) throw result.error;
    if (result.status !== 0) throw new Error(`PHP command failed (${result.status}): ${args.join(' ')}`);
}
