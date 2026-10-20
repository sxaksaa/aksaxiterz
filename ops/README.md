# Aksa Xiterz operations

## Storefront performance

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
