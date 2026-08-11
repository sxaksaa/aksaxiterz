# Aksa Xiterz operations

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
