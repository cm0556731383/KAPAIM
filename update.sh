#!/usr/bin/env bash
#
# Brings an installed system up to the latest version on GitHub (DEPLOY.md,
# section 6). Data and settings stay as they are.
#
#   ./update.sh

set -Eeuo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

step() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

DOCKER=(docker)
if ! docker info >/dev/null 2>&1; then
    DOCKER=(sudo docker)
fi
compose() { "${DOCKER[@]}" compose "$@"; }

step "גיבוי מסד הנתונים לפני העדכון"
./backup-database.sh

step "הורדת הגרסה החדשה"
git pull --ff-only

step "עדכון ספריות ובנייה"
compose build
compose run --rm --no-deps -e HOME=/tmp app composer install --no-dev --optimize-autoloader --no-interaction --no-progress
compose run --rm assets

step "עדכון מסד הנתונים והפעלה מחדש"
compose up -d
compose exec -T app php artisan migrate --force
compose exec -T app php artisan optimize:clear
compose restart queue scheduler

step "העדכון הושלם"
