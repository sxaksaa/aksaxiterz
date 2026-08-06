# Aksa Xiterz operations

## Monitoring

The `/up` endpoint checks application boot, database access, cache read/write, and Laravel storage permissions. Add `https://aksaxiterz.com/up` to an external uptime monitor with a five-minute interval and enable email or Discord alerts.

For critical log alerts, set `LOG_STACK=daily,slack` and `LOG_SLACK_WEBHOOK_URL` in the production `.env`, then run `php artisan config:cache`. Never commit the webhook URL.

## Database backups

`php artisan ops:backup-database` creates a compressed, timestamped database dump. The scheduler runs it daily at 02:30 and deletes backups older than `BACKUP_RETENTION_DAYS`.

The deploy script creates a backup before applying migrations. Production must have `mysqldump` installed and the scheduler cron active. Keep `storage/app/backups` private and periodically copy backups to a separate server or encrypted cloud storage.

To inspect a backup without touching production:

1. Copy the `.sql.gz` file to a safe test machine.
2. Decompress it.
3. Import it into a new empty test database.
4. Point a staging `.env` to that database and run the smoke tests.

Never test restoration against the live production database.

## Verification after deploy

Run these checks after every production deployment:

```text
php artisan schedule:list
php artisan ops:backup-database
curl --fail https://aksaxiterz.com/up
curl --fail https://aksaxiterz.com/sitemap.xml
```
