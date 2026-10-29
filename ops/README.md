# Aksa Xiterz operations

## Runtime baseline: 23 October 2026

The application uses PHP 8.5.12+, Laravel 13, Node.js 24.22.1 LTS and npm 12.2.1.
Composer and npm lockfiles record the tested versions, including PHPUnit 13 and
Vite 8. The Vite browser target remains Chrome/Edge 107, Firefox 104 and Safari 16
to preserve the previous build's browser support. Cache payloads are arrays,
with object unserialization disabled; existing session and cookie settings remain.

Windows project commands and VS Code use `C:/tools/php85/php.exe`. Laragon uses
the PHP 8.5.12 TS build with Apache 2.4.69. Production uses the signed
`ppa:ondrej/php` packages on Ubuntu 24.04, restricted via
`/etc/apt/preferences.d/aksaxiterz-php85` to the selected PHP branch. Node.js stays
on the latest available production LTS release; newer Current releases are not
the production baseline. Nginx uses Ubuntu vendor packages; MariaDB uses the
official signed repository for the 10.11 LTS series described below.

Windows security refresh on 29 October 2026: both the CLI NTS and Laragon TS
PHP installations use the official `8.5.12-1` rebuild with Brotli 1.2.1.
`php -v` still reports 8.5.12; check `curl_version()['brotli_version']` as well.
Laragon 8.8.0 selects MySQL 9.7.4 LTS with its existing `mysql-9.7` data folder,
Node.js 24.22.1 and Nginx 1.30.6. MySQL classic and X Protocol listeners bind
to 127.0.0.1 for local development. Existing database directories are retained.

Nginx must point to `/run/php/php8.5-fpm.sock` and both CLI and FPM must use PHP
8.5 before running the updated deploy script. The script checks these conditions
before replacing application code. PHP 8.3 remains installed for rollback only.
An application rollback to the pre-upgrade revision also requires the previous
Composer vendor tree or reinstalling its lockfile. Keep runtime config and data
backups outside the web root. Never downgrade PHP while Laravel 13 is serving.
The backup root `/var/backups/aksaxiterz` is owned by root with mode 0700,
including when older archives inside it have less restrictive file modes.
Verify that `www-data` cannot traverse it after changes to backup handling.

## SSH and database maintenance

Verified on 29 October 2026: SSH accepts public-key authentication only, and
MariaDB server/client/provider packages run 10.11.19. Password-only SSH was
denied while a fresh key login succeeded. Database account/grant fingerprints
and active server settings were preserved; all five compression providers and
application health checks passed. A fresh backup restored successfully after
the update, and the temporary restore database was removed.

Install `aksaxiterz-sshd-hardening.conf` as
`/etc/ssh/sshd_config.d/00-aksaxiterz-hardening.conf`. The early name is required
because OpenSSH accepts the first value and cloud-init sets password login in
`50-cloud-init.conf`. Root access uses the existing authorized SSH keys;
password and keyboard-interactive login are disabled. Keep the current session
open, arm a timed configuration rollback, validate with `sshd -t` and `sshd -T`,
then reload `ssh.service`. Confirm a fresh key login and denial of password-only
authentication before cancelling rollback. Do not remove authorized keys.

MariaDB production updates stay within the 10.11 LTS series. The repository
template `aksaxiterz-mariadb.sources` uses Ubuntu noble amd64 and a dedicated
`Signed-By` keyring. Obtain the Community signing key from
`https://mariadb.org/mariadb_release_signing_key.pgp` and verify its primary
fingerprint is `177F4010FE56CA3336300305F1656F24C74CD1D8` before installing it
as `/usr/share/keyrings/aksaxiterz-mariadb.gpg`. Install the sources template in
`/etc/apt/sources.list.d/aksaxiterz-mariadb.sources` and the preferences template
as `/etc/apt/preferences.d/aksaxiterz-mariadb`. Only the MariaDB package family,
its client library, Galera and mysql-common receive the higher priority.

Before updating, verify a fresh logical backup can restore, preserve system
accounts and configuration, and save a cold datadir snapshot plus old packages
inside the protected backup root. Review an APT simulation and require a 10.11
server candidate, all installed compression providers, and no package removals.
Use a brief application maintenance window, pause scheduling and allow running
scheduled jobs to finish before stopping MariaDB for its snapshot. Preserve
locally modified configuration during installation. Verify root socket login,
application database access, account/grant fingerprints, compression providers,
loopback binding, `mariadb-upgrade`, and backup restoration before completion.
Keep a failed upgrade in maintenance; do not restart an old binary on upgraded
data or restore a pre-upgrade snapshot over newer live writes.

## Storefront performance

On phones below 640px, the homepage hero and package cards use a more compact
layout. The existing two-option IDR/USD control sits beside the catalog/package
heading below 1024px, and in the navbar above that width; catalog and product
menus do not duplicate it. Other checkout/cart pages retain the menu control on
small screens. Per-day USD estimates display two decimal places with an
approximation mark; raw prices, full package totals, invoices, and payment
calculations retain their original precision. Selecting a package continues to
scroll to the existing checkout summary. Verification: 252 PHP tests and 20
targeted desktop/mobile browser checks passed for these layout/currency changes.

The full brand intro plays automatically on the homepage, without a query
parameter, and respects reduced motion and the once-per-tab session setting.
The intro intentionally delays the content reveal and can increase LCP.
Devices with a coarse pointer and no hover keep the automatic logo intro,
but avoid animated intro/reveal filters, card/navbar backdrop blur, and fixed
background attachment to reduce rendering work during motion and scrolling.
On those devices, scrolling elements reveal once and stay visible; homepage
cards use only the viewport reveal rather than a second staged animation.
The text entrance uses 420ms animations and finishes its sequence after 1400ms;
the counters start after 1600ms and update at most about 30 times per second
for 600ms. Purchase notifications wait until the initial entrance settles and
use one simple entrance/exit plus their progress bar, without the sheen, icon
pulse, separate text animations, blur, or changing shadows. Hidden purchase
notifications explicitly use `display: none`, including after dismiss/reload.
The desktop effects remain unchanged. Intro, scroll, reduced-motion, menu,
currency, and narrow-layout checks passed on Chromium desktop/mobile; the
touch-rendering checks also ran with 4x CPU throttling. This does not measure
GPU frame pacing on a physical phone, which still requires device testing.
The follow-up motion changes passed 62 relevant browser checks across desktop
and mobile, excluding the previously documented checkout-summary and footer
layout cases. These include scrolling back to revealed cards, counter/entrance
sequencing, notification dismissal and snooze across reload, and soft navigation.
QR libraries load on demand when a payment QR
is rendered. Navbar and footer use the compressed WebP logo; the original PNG
assets remain available for payment integrations and social metadata.

Run `npm run build` during deployment. The deploy script installs the Nginx
performance snippet, including seven-day caching for `/images/brand/` and
one-year immutable caching for hashed Vite assets. Confirm response headers on
the live server after deployment. Keep HTML and payment responses out of these
static asset caching rules.

Initial optimization verification on 20 October 2026: 252 PHP tests passed, lint and production
build passed, and 58 browser checks passed across desktop and mobile. The wider
browser suite also has a stale checkout-summary assertion (expects four columns;
the existing template has one) and a timing-sensitive footer assertion. The
checkout assertion also fails on the unchanged HEAD. A settled-layout comparison
of HEAD and the optimized version produced the same 1487.45px body height at a
2560x1440 viewport, although the footer test passed on HEAD during its animation.

A single cold-context experiment with the intro disabled (390x844, localhost,
without network/CPU throttling) measured LCP at 5468ms with the intro and 132ms
without it. The automatic intro has since been restored at the user's request;
the 132ms result does not describe the current default experience or predict
production PageSpeed scores or real-user Core Web Vitals.
Compare production Lighthouse results after deployment and monitor the rolling
28-day field data separately.

## Monitoring

The `/up` endpoint checks application boot, database access, cache read/write, and Laravel storage permissions. Add `https://aksaxiterz.com/up` to an external uptime monitor with a five-minute interval and enable email or Discord alerts.

Use an external provider for full outage coverage. A monitor running on the same VPS cannot send an alert when the VPS itself is offline. Configure the provider to request `GET https://aksaxiterz.com/up`, expect HTTP 200, check every five minutes, and email `akbarsalahudinpurnomo@gmail.com` after two consecutive failures. The repository intentionally does not store provider credentials or SMTP passwords.

For critical log alerts, set `LOG_STACK=daily,slack` and `LOG_SLACK_WEBHOOK_URL` in the production `.env`, then run `php artisan config:cache`. Never commit the webhook URL.

## Database backups

Production uses one VPS-managed backup system: `/usr/local/sbin/aksaxiterz-backup`. It creates a compressed database dump, a compressed application-files archive, SHA-256 checksums, and a status file under `/var/backups/aksaxiterz/auto`.

The VPS cron runs the backup daily at 03:10 WIB and keeps 14 days. The deploy script runs the same complete backup before applying migrations. Laravel Scheduler remains responsible only for payment, order-expiration, and stock-reservation jobs.

To list the latest database and application-files backups from Windows PowerShell:

```text
ssh aksaxiterz-vps "find /var/backups/aksaxiterz/auto/db -type f -name '*.sql.gz' -printf '%f\n' | sort | tail -1"
ssh aksaxiterz-vps "find /var/backups/aksaxiterz/auto/files -type f -name '*.tar.gz' -printf '%f\n' | sort | tail -1"
```

Then replace the placeholders with those results and choose a local destination:

```text
scp aksaxiterz-vps:/var/backups/aksaxiterz/auto/db/<database-filename> D:\Backup\Aksaxiterz\
scp aksaxiterz-vps:/var/backups/aksaxiterz/auto/files/<files-filename> D:\Backup\Aksaxiterz\
```

The laptop only needs to be on during this manual download; scheduled VPS backups do not depend on the laptop.

To inspect a backup without touching production:

1. Copy the `.sql.gz` file to a safe test machine.
2. Decompress it.
3. Import it into a new empty test database.
4. Point a staging `.env` to that database and run the smoke tests.

Never test restoration against the live production database.

Production includes a safe restore verifier. It validates the latest checksum, imports the dump into a uniquely named temporary database, checks its tables and migration history, and removes the temporary database automatically:

```text
/usr/local/sbin/aksaxiterz-verify-backup
```

## Verification after deploy

QRIS limits each customer to 20 retained unpaid invoices by default, including cancelled
and replaced invoices. `GOPAY_QRIS_MAX_UNPAID_INVOICES` can lower this limit (maximum 50,
also bounded below the configured unique-amount pool when possible). Paid invoices do
not consume the customer budget. Existing amount quarantine and late-payment recovery
remain in effect; cancelling another invoice does not immediately restore this budget.
At the limit, continue an existing payment or reconcile it through support.

Crypto and Binance Pay also limit each customer to 20 retained unpaid invoices
per payment method, across all tokens/networks and checkout paths. Configure
`CRYPTO_DIRECT_MAX_UNPAID_INVOICES` and `BINANCE_PAY_MAX_UNPAID_INVOICES` to lower
the limit (maximum 50, also bounded below the configured amount pool when possible).
Cancelled invoices count until their payment recovery window expires; paid
invoices do not. A denied retry preserves the original invoice and reservation.
Binance payment verification still runs before deciding whether to replace it.

Crypto and Binance scanners rotate through eligible order IDs using separate cursors
in the shared Laravel cache. Progress is saved before provider calls, so failed calls
and legacy payloads cannot pin the first batch. Keep the normal shared production cache
configured; no additional cron job or database migration is required.

Run these checks after every production deployment:

```text
php artisan schedule:list
/usr/local/sbin/aksaxiterz-backup
curl --fail https://aksaxiterz.com/up
curl --fail https://aksaxiterz.com/sitemap.xml
```

## USDT Avalanche C-Chain

Set `CRYPTO_AVAXC_ADDRESS` to the receiving wallet's USDT Avalanche C-Chain address in the production environment. Do not assume a BSC exchange deposit address also accepts AVAXC. Checkout shows AVAXC only after this address is configured.

Defaults use native USDT (6 decimals), contract `0x9702230a8ea53601f5cd2dc00fdbc13d4df4a8c7`, and the Avalanche mainnet RPC. USDT.e is not supported. Sources: https://tether.to/es/supported-protocols/ and https://build.avax.network/docs/dapps.

Optional RPC settings and `BINANCE_AVAXC_NETWORK=AVAXC` are in `.env.example`. Existing Binance deposit verification settings also apply; the receiving address must belong to the account queried by those credentials when using Binance as the primary verifier.

After deployment, run `php artisan config:cache` and ensure the existing crypto verification scheduler is running. Verify an actual small AVAXC payment before announcing availability. No new cron job or database migration is required.
