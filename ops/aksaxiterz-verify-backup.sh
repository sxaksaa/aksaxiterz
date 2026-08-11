#!/usr/bin/env bash

set -euo pipefail

readonly BACKUP_ROOT="/var/backups/aksaxiterz/auto/db"
readonly DATABASE_PREFIX="aksaxiterz_restore_test_"

fail() {
    printf 'Backup restore verification failed: %s\n' "$1" >&2
    exit 1
}

[[ ${EUID} -eq 0 ]] || fail "run this script as root"
command -v mysql >/dev/null || fail "mysql client is not installed"
command -v gzip >/dev/null || fail "gzip is not installed"

latest_backup="$(find "${BACKUP_ROOT}" -maxdepth 1 -type f -name 'aksaxiterz-db-*.sql.gz' -printf '%p\n' | sort | tail -1)"
[[ -n "${latest_backup}" ]] || fail "no database backup found"

checksum_file="${latest_backup}.sha256"
[[ -f "${checksum_file}" ]] || fail "missing checksum for $(basename -- "${latest_backup}")"

(
    cd "${BACKUP_ROOT}"
    sha256sum -c "$(basename -- "${checksum_file}")"
)
gzip -t "${latest_backup}"

restore_database="${DATABASE_PREFIX}$(date +%Y%m%d%H%M%S)_${RANDOM}"
[[ "${restore_database}" =~ ^aksaxiterz_restore_test_[0-9]{14}_[0-9]+$ ]] || fail "unsafe temporary database name"

cleanup() {
    mysql -e "DROP DATABASE IF EXISTS \`${restore_database}\`;" >/dev/null 2>&1 || true
}
trap cleanup EXIT

mysql -e "CREATE DATABASE \`${restore_database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gzip -dc "${latest_backup}" | mysql "${restore_database}"

table_count="$(mysql -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${restore_database}';")"
[[ "${table_count}" =~ ^[0-9]+$ ]] || fail "could not count restored tables"
(( table_count > 0 )) || fail "restore produced no tables"

migrations_present="$(mysql -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${restore_database}' AND table_name='migrations';")"
[[ "${migrations_present}" == "1" ]] || fail "restored database does not contain the migrations table"

migration_count="$(mysql -Nse "SELECT COUNT(*) FROM \`${restore_database}\`.migrations;")"
(( migration_count > 0 )) || fail "restored migrations table is empty"

printf 'Backup restore verified: %s\n' "$(basename -- "${latest_backup}")"
printf 'Restored tables: %s; migration rows: %s\n' "${table_count}" "${migration_count}"
printf 'Temporary database removed automatically: %s\n' "${restore_database}"
