#!/usr/bin/env bash
#
# Fresh installation of the kapaim CRM on a new server — an empty system,
# no data. Full instructions: DEPLOY.md.
#
#   ./install.sh <admin-email> [site-address]
#   ./install.sh owner@example.co.il https://crm.example.co.il
#
# Safe to run again: it keeps an existing .env, APP_KEY and data, and only
# creates the first admin when there are no users yet.

set -Eeuo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

ADMIN_EMAIL="${1:-}"
SITE_URL="${2:-}"

if [[ -z "${ADMIN_EMAIL}" ]]; then
    echo "שימוש: ./install.sh <admin-email> [site-address]" >&2
    echo "לדוגמה: ./install.sh owner@example.co.il https://crm.example.co.il" >&2
    exit 1
fi

step() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

# ----- Docker -----
if ! command -v docker >/dev/null 2>&1; then
    echo "Docker אינו מותקן. התקנה: https://docs.docker.com/engine/install/" >&2
    exit 1
fi

DOCKER=(docker)
if ! docker info >/dev/null 2>&1; then
    DOCKER=(sudo docker)
fi
compose() { "${DOCKER[@]}" compose "$@"; }

# ----- Who owns the files (the containers run as this user) -----
APP_UID="$(id -u)"
APP_GID="$(id -g)"
if [[ "${APP_UID}" == "0" ]]; then
    # Running as root: hand the folder to a regular uid, so PHP inside the
    # containers never runs as root and can still write storage/.
    APP_UID=1000
    APP_GID=1000
    chown -R "${APP_UID}:${APP_GID}" .
fi

# ----- .env -----
if [[ ! -f .env ]]; then
    step "יצירת קובץ .env"
    cp .env.example .env
    DB_PASSWORD="$(head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 32)"
    sed -i \
        -e "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" \
        -e "s|^APP_UID=.*|APP_UID=${APP_UID}|" \
        -e "s|^APP_GID=.*|APP_GID=${APP_GID}|" \
        .env
    [[ "${APP_UID}" == "$(id -u)" ]] || chown "${APP_UID}:${APP_GID}" .env
fi

if [[ -n "${SITE_URL}" ]]; then
    sed -i "s|^APP_URL=.*|APP_URL=${SITE_URL}|" .env
fi

# ----- Folders the app writes to -----
mkdir -p storage/app/private storage/app/public storage/framework/{cache,sessions,views,testing} storage/logs bootstrap/cache public/build
[[ "$(id -u)" != "0" ]] || chown -R "${APP_UID}:${APP_GID}" storage bootstrap/cache public/build

step "בניית הקונטיינרים"
compose build

step "התקנת ספריות PHP"
compose run --rm --no-deps -e HOME=/tmp app composer install --no-dev --optimize-autoloader --no-interaction --no-progress

if ! grep -q '^APP_KEY=base64:' .env; then
    step "יצירת מפתח הצפנה"
    compose run --rm --no-deps app php artisan key:generate --force
fi

step "בניית העיצוב (CSS/JS)"
compose run --rm assets

step "הפעלת המערכת"
compose up -d

step "יצירת טבלאות במסד הנתונים"
compose exec -T app php artisan migrate --force

step "נתוני בסיס (סטטוסים, אמצעי תשלום, תבניות)"
compose exec -T app php artisan db:seed --class=InstallSeeder --force

compose exec -T app php artisan storage:link >/dev/null 2>&1 || true

step "משתמשת מנהלת"
compose exec -T app php artisan app:create-admin "${ADMIN_EMAIL}" --if-none

PORT="$(grep '^APP_PORT=' .env | cut -d= -f2)"
step "ההתקנה הושלמה"
echo "המערכת פועלת בכתובת http://127.0.0.1:${PORT:-8090} (מקומית בשרת)."
echo "השלב הבא: חיבור הדומיין עם HTTPS — ראו DEPLOY.md, סעיף 4."
