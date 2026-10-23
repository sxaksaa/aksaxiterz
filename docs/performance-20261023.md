# Performance changes, 23 October 2026

## Loading

Production Vite build, before and after:

| Initial JavaScript | Before | After |
| --- | ---: | ---: |
| Uncompressed | 105.03 kB | 71.37 kB |
| Gzip | 27.91 kB | 20.03 kB |

Checkout (12.62 kB / 3.60 kB gzip) loads when a checkout form is present.
Payment processing (22.23 kB / 5.99 kB gzip) loads on the first payment API call.
QR libraries retain their existing dynamic imports. Total downloaded bytes depend
on which features a visitor actually opens; this is not a reduction in all code.

## Interaction changes

- Price width probes are appended together and measured before updating visible
  prices. This avoids forcing layout separately for every price during currency changes.
- Navbar scroll work is coalesced to one callback per animation frame.
- Touch devices retain currency opacity/translation animations without moving blur.

`tests/Browser/performance.spec.js` records scroll frame samples, layout count,
script time and long tasks under Pixel 7 emulation with 4x CPU throttling.
This is a diagnostic sample, not physical-device FPS or a guarantee of smoothness
on every phone. A physical-device trace remains necessary to investigate issues
specific to the user's phone.

## Server work

A repeatable isolated SQLite fixture contains 500 orders, including 50 expired
pending orders. One dashboard lifetime fragment request was measured before and
after removing the redundant bulk expiry pass from filter requests:

| Metric | Before | After |
| --- | ---: | ---: |
| SQL statements | 165 | 14 |
| Application duration, one local run | 84.19 ms | 45.33 ms |
| Database duration, one local run | 4.65 ms | 1.60 ms |

Query reduction is the structural result. Duration is indicative only: local runs
include rendering/cold-cache effects and are not production latency measurements.
Revenue and paid-order totals are unchanged. The scheduler, bounded traffic
fallback and full-dashboard expiry pass still run; filters no longer perform
bulk cancellation themselves.

Authenticated checkout and dashboard responses now expose `Server-Timing` with
`app` and `db` durations, including checkout invoice POSTs. No SQL text, payment
credentials or customer data are included. This permits separating server work
from network and animation delay. Checkout invoice creation was not load-tested
against production payment providers.

See `resources/js/README.md` for profiling commands. These changes preserve
Laravel/Blade, checkout validation and the existing visual animation sequences.
