#!/usr/bin/env bash

set -euo pipefail

readonly EXPECTED_APP_DIR="/var/www/aksaxiterz"
readonly DEPLOY_BRANCH="main"
readonly PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.3-fpm}"
readonly WEB_SERVICE="${WEB_SERVICE:-nginx}"

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

unexpected_env_file="$(find "${APP_DIR}" -maxdepth 1 -name '.env*' \
    ! -name '.env' ! -name '.env.example' -print -quit)"
[[ -z "${unexpected_env_file}" ]] || fail \
    "move the environment copy outside the application directory: $(basename -- "${unexpected_env_file}")"

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

printf 'Deploy complete: %s\n' "$(git rev-parse --short HEAD)"
