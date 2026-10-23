# Monthly and Lifetime dashboard performance

The report previously hydrated every matching order, item, related product and
package as Eloquent models. Monthly/Lifetime processed substantially more rows
than Hourly/Daily, with repeated date casting and intermediate product arrays.

Changes:

- Read only report columns as lightweight query results, with batched item/name/price reads.
- Preserve stored item names/prices, current-name fallback and legacy package fallback.
- Extract SQL datetime bucket prefixes for hourly/daily/monthly reports; retain Carbon
  for ISO week calculations and nonstandard timestamps.
- Accumulate product totals directly rather than allocating/grouping one array per item.
- Preserve payment amount fallback rules, fees, paid-date fallback and license counts.
- No caching or deliberate data freshness delay was added.

Read-only CLI controller profiling on the production server/database:

| Range | Existing implementation | Candidate implementation |
| --- | ---: | ---: |
| Hourly | 252 ms | 252 ms |
| Daily | 239 ms | 200 ms |
| Monthly | 1,986 ms | 406 ms |
| Lifetime | 1,721 ms | 411 ms |

These are individual PHP controller/render measurements, excluding browser/network
and chart animation. They are not latency guarantees. Monthly/Lifetime still read
more rows, but the object allocation and processing overhead is much smaller.

SHA-256 comparisons of the selected statistics, chart data, top products and
payment split were identical for all four ranges on the same production data.
Only hashes/timings were printed, not customer/payment details. Candidate code
ran in a separate CLI process before deployment; web workers were unaffected.

Regression coverage includes method filters, range boundaries, ISO week buckets,
cart snapshot prices/names, legacy paid dates, crypto fallback amounts and access controls.
