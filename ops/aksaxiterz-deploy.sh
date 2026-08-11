#!/usr/bin/env bash

set -euo pipefail

readonly EXPECTED_APP_DIR="/var/www/aksaxiterz"
readonly DEPLOY_BRANCH="main"
readonly PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.3-fpm}"
readonly WEB_SERVICE="${WEB_SERVICE:-nginx}"
readonly VPS_BACKUP_SCRIPT="/usr/local/sbin/aksaxiterz-backup"
readonly VPS_RESTORE_VERIFY_SCRIPT="/usr/local/sbin/aksaxiterz-verify-backup"
readonly NGINX_SITE_CONFIG="/etc/nginx/sites-available/aksaxiterz"
readonly NGINX_PERFORMANCE_SNIPPET="/etc/nginx/snippets/aksaxiterz-performance.conf"

fail() {
    printf 'Deploy failed: %s\n' "$1" >&2
    exit 1
}

[[ ${EUID} -eq 0 ]] || fail "run this script as root"
[[ -d "${EXPECTED_APP_DIR}" ]] || fail "app directory does not exist: ${EXPECTED_APP_DIR}"

APP_DIR="$(realpath -e -- "${EXPECTED_APP_DIR}")"
readonly APP_DIR
[[ "${APP_DIR}" == "${EXPECTED_APP_DIR}" ]] || fail "app directory must resolve exactly to ${EXPECTED_APP_DIR}"
[[ -d "${APP_DIR}/.git" ]] || fail "app directory is not a Git checkout"
[[ -f "${APP_DIR}/.env" ]] || fail "missing ${APP_DIR}/.env"
[[ -x "${VPS_BACKUP_SCRIPT}" ]] || fail "missing executable VPS backup script: ${VPS_BACKUP_SCRIPT}"

unexpected_env_file="$(find "${APP_DIR}" -maxdepth 1 -name '.env*' \
    ! -name '.env' ! -name '.env.example' -print -quit)"
[[ -z "${unexpected_env_file}" ]] || fail \
    "move the environment copy outside the application directory: $(basename -- "${unexpected_env_file}")"

environment_errors="$(
    php -r '
        $values = parse_ini_file($argv[1], false, INI_SCANNER_RAW);

        if (! is_array($values)) {
            echo "unable to parse production .env";
            exit;
        }

        $errors = [];
        $truthy = static fn ($value): bool => in_array(
            strtolower(trim((string) $value)),
            ["1", "true", "yes", "on"],
            true
        );
        $required = static function (array $keys) use ($values, &$errors): void {
            foreach ($keys as $key) {
                if (trim((string) ($values[$key] ?? "")) === "") {
                    $errors[] = $key." is empty";
                }
            }
        };

        if (($values["APP_ENV"] ?? "") !== "production") {
            $errors[] = "APP_ENV must be production";
        }

        if ($truthy($values["APP_DEBUG"] ?? false)) {
            $errors[] = "APP_DEBUG must be false";
        }

        if (strtolower(trim((string) ($values["LOG_LEVEL"] ?? ""))) === "debug") {
            $errors[] = "LOG_LEVEL cannot be debug in production";
        }

        $required(["APP_KEY"]);

        if (! str_starts_with((string) ($values["APP_URL"] ?? ""), "https://")) {
            $errors[] = "APP_URL must use https";
        }

        if (trim((string) ($values["TRUSTED_PROXIES"] ?? "")) === "*") {
            $errors[] = "TRUSTED_PROXIES cannot be wildcard";
        }

        if ($truthy($values["GOPAY_QRIS_ENABLED"] ?? false)) {
            $required([
                "GOPAY_QRIS_STATIC_PAYLOAD",
                "GOPAY_QRIS_MERCHANT_REFERENCE",
                "GOPAY_QRIS_WEBHOOK_TOKEN",
                "GOPAY_QRIS_WEBHOOK_SECRET",
                "GOPAY_QRIS_ALLOWED_DEVICES",
            ]);

            $webhookToken = (string) ($values["GOPAY_QRIS_WEBHOOK_TOKEN"] ?? "");
            $webhookSecret = (string) ($values["GOPAY_QRIS_WEBHOOK_SECRET"] ?? "");

            if ($webhookToken !== "" && strlen($webhookToken) < 32) {
                $errors[] = "GOPAY_QRIS_WEBHOOK_TOKEN must contain at least 32 characters";
            }

            if ($webhookSecret !== "" && strlen($webhookSecret) < 32) {
                $errors[] = "GOPAY_QRIS_WEBHOOK_SECRET must contain at least 32 characters";
            }

            if ($webhookToken !== "" && $webhookSecret !== "" && hash_equals($webhookToken, $webhookSecret)) {
                $errors[] = "GOPAY_QRIS_WEBHOOK_TOKEN and GOPAY_QRIS_WEBHOOK_SECRET must be different";
            }

            if ((int) ($values["GOPAY_QRIS_RECOVERY_HOURS"] ?? 0) < 72) {
                $errors[] = "GOPAY_QRIS_RECOVERY_HOURS must be at least 72";
            }

            if ((int) ($values["GOPAY_QRIS_RECOVERY_HOURS"] ?? 0) > 168) {
                $errors[] = "GOPAY_QRIS_RECOVERY_HOURS cannot exceed 168";
            }

            $delayedRecoveryMinutes = (int) ($values["GOPAY_QRIS_DELAYED_RECOVERY_MIN_MINUTES"] ?? 0);

            if ($delayedRecoveryMinutes < 60 || $delayedRecoveryMinutes > 1440) {
                $errors[] = "GOPAY_QRIS_DELAYED_RECOVERY_MIN_MINUTES must be between 60 and 1440";
            }

            $notificationMaxAgeHours = (int) ($values["GOPAY_QRIS_NOTIFICATION_MAX_AGE_HOURS"] ?? 0);
            $recoveryHours = (int) ($values["GOPAY_QRIS_RECOVERY_HOURS"] ?? 0);

            if ($notificationMaxAgeHours < $recoveryHours || $notificationMaxAgeHours > 168) {
                $errors[] = "GOPAY_QRIS_NOTIFICATION_MAX_AGE_HOURS must cover recovery and cannot exceed 168";
            }

            $amountQuarantineHours = (int) ($values["GOPAY_QRIS_AMOUNT_QUARANTINE_HOURS"] ?? 0);

            if ($amountQuarantineHours < 168 || $amountQuarantineHours > 720) {
                $errors[] = "GOPAY_QRIS_AMOUNT_QUARANTINE_HOURS must be between 168 and 720";
            }
        }

        echo implode("; ", $errors);
    ' "${APP_DIR}/.env"
)"
[[ -z "${environment_errors}" ]] || fail "${environment_errors}"

safe_directories=""
if safe_directories="$(git config --global --get-all safe.directory 2>/dev/null)"; then
    :
fi
safe_directory_count=0
while IFS= read -r safe_directory; do
    if [[ "${safe_directory}" == "${APP_DIR}" ]]; then
        ((safe_directory_count += 1))
    fi
done <<< "${safe_directories}"
if (( safe_directory_count != 1 )); then
    if (( safe_directory_count > 0 )); then
        git config --global --unset-all safe.directory '^/var/www/aksaxiterz$'
    fi
    git config --global --add safe.directory "${APP_DIR}"
fi

cd "${APP_DIR}"
git fetch --prune origin "${DEPLOY_BRANCH}"
git reset --hard "origin/${DEPLOY_BRANCH}"

chown root:www-data .env
chmod 0640 .env

export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci
npm run build
"${VPS_BACKUP_SCRIPT}"
php artisan migrate --force

# A pre-existing storage path must be the expected symlink.
install -d -o www-data -g www-data -m 0775 "${APP_DIR}/storage/app/public"
storage_link="${APP_DIR}/public/storage"
expected_storage_target="$(realpath -e -- "${APP_DIR}/storage/app/public")"

if [[ -L "${storage_link}" ]]; then
    if actual_storage_target="$(realpath -e -- "${storage_link}" 2>/dev/null)" && [[ "${actual_storage_target}" == "${expected_storage_target}" ]]; then
        :
    else
        printf 'Repairing invalid public/storage symlink.\n'
        unlink -- "${storage_link}"
    fi
elif [[ -e "${storage_link}" ]]; then
    fail "public/storage exists but is not a symlink"
fi

if [[ ! -L "${storage_link}" ]]; then
    php artisan storage:link
fi

[[ -L "${storage_link}" ]] || fail "public/storage symlink was not created"
actual_storage_target="$(realpath -e -- "${storage_link}")" || fail "public/storage is a broken symlink"
[[ "${actual_storage_target}" == "${expected_storage_target}" ]] || fail "public/storage does not point to storage/app/public"

php artisan optimize:clear
php artisan optimize
php artisan payments:verify-gopay-config
php artisan schedule:list >/dev/null

install -o root -g root -m 0644 \
    "${APP_DIR}/ops/aksaxiterz-nginx-performance.conf" \
    "${NGINX_PERFORMANCE_SNIPPET}"
install -o root -g root -m 0755 \
    "${APP_DIR}/ops/aksaxiterz-verify-backup.sh" \
    "${VPS_RESTORE_VERIFY_SCRIPT}"

[[ -f "${NGINX_SITE_CONFIG}" ]] || fail "missing Nginx site config: ${NGINX_SITE_CONFIG}"
if ! grep -Fq "include ${NGINX_PERFORMANCE_SNIPPET};" "${NGINX_SITE_CONFIG}"; then
    nginx_site_backup="$(mktemp /tmp/aksaxiterz-nginx-site.XXXXXX)"
    cp --preserve=mode,ownership,timestamps "${NGINX_SITE_CONFIG}" "${nginx_site_backup}"
    sed -i "/^[[:space:]]*client_max_body_size[[:space:]]/a\\    include ${NGINX_PERFORMANCE_SNIPPET};" "${NGINX_SITE_CONFIG}"

    if ! nginx -t; then
        cp --preserve=mode,ownership,timestamps "${nginx_site_backup}" "${NGINX_SITE_CONFIG}"
        rm -f -- "${nginx_site_backup}"
        fail "Nginx rejected the performance snippet; original site config restored"
    fi

    rm -f -- "${nginx_site_backup}"
fi

nginx -t

readonly SCHEDULER_CRON_FILE="/etc/cron.d/aksaxiterz"
[[ -f "${SCHEDULER_CRON_FILE}" ]] || fail "missing Laravel scheduler cron: ${SCHEDULER_CRON_FILE}"

# Debian cron ignores a cron.d file when its final line is not newline-terminated.
cron_last_byte="$(tail -c 1 "${SCHEDULER_CRON_FILE}" | od -An -t x1 | tr -d '[:space:]')"
if [[ -s "${SCHEDULER_CRON_FILE}" && "${cron_last_byte}" != "0a" ]]; then
    printf '\n' >> "${SCHEDULER_CRON_FILE}"
fi

chown root:root "${SCHEDULER_CRON_FILE}"
chmod 0644 "${SCHEDULER_CRON_FILE}"
grep -Eq '^[^#].*artisan[[:space:]]+schedule:run' "${SCHEDULER_CRON_FILE}" || \
    fail "Laravel scheduler cron does not run artisan schedule:run"
systemctl is-active --quiet cron || fail "cron service is not active"
systemctl restart cron
systemctl is-active --quiet cron || fail "cron service did not restart cleanly"

# Application source is read-only to www-data; only Laravel runtime paths are writable.
chown -R root:www-data "${APP_DIR}"
find "${APP_DIR}" \
    -path "${APP_DIR}/storage" -prune -o \
    -path "${APP_DIR}/bootstrap/cache" -prune -o \
    -type d -exec chmod u+rwx,go+rx,go-w {} +
find "${APP_DIR}" \
    -path "${APP_DIR}/storage" -prune -o \
    -path "${APP_DIR}/bootstrap/cache" -prune -o \
    -type f ! -path "${APP_DIR}/.env" -exec chmod u+rw,go+r,go-w {} +

chown root:www-data "${APP_DIR}/.env"
chmod 0640 "${APP_DIR}/.env"
chown -R www-data:www-data "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"
find "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache" -type d -exec chmod 0775 {} +
find "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache" -type f -exec chmod 0664 {} +

systemctl reload "${PHP_FPM_SERVICE}"
systemctl reload "${WEB_SERVICE}"

install -o root -g root -m 0755 \
    "${APP_DIR}/ops/aksaxiterz-deploy.sh" \
    "/usr/local/bin/aksaxiterz-deploy"

printf 'Deploy complete: %s\n' "$(git rev-parse --short HEAD)"
