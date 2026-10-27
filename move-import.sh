#!/usr/bin/env bash
#
# Restores, on a server already set up with ./install.sh, the file made by
# ./move-export.sh on the old server: the database, the uploaded files and
# the encryption key (APP_KEY). Whatever this server held before is
# replaced — a backup of it is taken first. Full steps: DEPLOY.md, section 9.
#
#   ./move-import.sh <kapaim-move_….tar.gz>           the move itself
#   ./move-import.sh <kapaim-move_….tar.gz> --trial   a trial while the old server still works:
#                                                     emails and scheduled tasks stay off here,
#                                                     so nothing goes out twice

set -Eeuo pipefail
umask 077

cd "$(dirname "${BASH_SOURCE[0]}")"

step() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

BUNDLE="${1:-}"
TRIAL=false
[[ "${2:-}" == "--trial" ]] && TRIAL=true
if [[ -z "${BUNDLE}" || ! -f "${BUNDLE}" ]]; then
    echo "שימוש: ./move-import.sh <kapaim-move_….tar.gz>" >&2
    exit 1
fi
if [[ ! -f .env ]]; then
    echo "המערכת עוד לא מותקנת כאן — קודם ./install.sh (DEPLOY.md, סעיף 3)." >&2
    exit 1
fi

DOCKER=(docker)
if ! docker info >/dev/null 2>&1; then
    DOCKER=(sudo docker)
fi
compose() { "${DOCKER[@]}" compose "$@"; }

# shellcheck disable=SC1091
set -a; source .env; set +a

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT
tar -C "${WORK}" -xzf "${BUNDLE}"

for part in database.dump storage-app.tar app-key.env; do
    [[ -f "${WORK}/${part}" ]] || { echo "הקובץ לא נוצר ב-move-export.sh (חסר ${part})." >&2; exit 1; }
done

# The old server's code must not be newer than this one's — its database could hold changes this code doesn't know.
OLD_VERSION="$(cat "${WORK}/version.txt" 2>/dev/null || echo unknown)"
if [[ "${OLD_VERSION}" != unknown ]] && ! git merge-base --is-ancestor "${OLD_VERSION}" HEAD 2>/dev/null; then
    echo "אזהרה: הגרסה בשרת הישן (${OLD_VERSION:0:7}) לא נמצאת בקוד כאן. מומלץ קודם git pull כאן." >&2
    read -r -p "להמשיך בכל זאת? (y/N) " answer
    [[ "${answer}" == "y" ]] || exit 1
fi

step "גיבוי מה שיש כרגע בשרת הזה"
./backup-database.sh

step "עצירת המערכת לזמן השחזור"
compose stop app queue scheduler nginx

step "מפתח ההצפנה של השרת הישן"
APP_KEY_LINE="$(grep '^APP_KEY=' "${WORK}/app-key.env")"
if grep -q '^APP_KEY=' .env; then
    sed -i "s|^APP_KEY=.*|${APP_KEY_LINE//|/\\|}|" .env
else
    echo "${APP_KEY_LINE}" >> .env
fi

step "שחזור מסד הנתונים"
compose exec -T postgres dropdb -U "${DB_USERNAME}" --if-exists --force "${DB_DATABASE}"
compose exec -T postgres createdb -U "${DB_USERNAME}" -O "${DB_USERNAME}" "${DB_DATABASE}"
compose exec -T postgres pg_restore -U "${DB_USERNAME}" -d "${DB_DATABASE}" --no-owner --no-privileges < "${WORK}/database.dump"

step "שחזור הקבצים שהועלו"
tar -C storage -xf "${WORK}/storage-app.tar"
if [[ "$(id -u)" == "0" ]]; then
    chown -R "${APP_UID:-1000}:${APP_GID:-1000}" storage/app
fi

step "הפעלה מחדש ועדכון מבנה מסד הנתונים"
# --force-recreate: the containers read .env (the new APP_KEY) only when created.
compose up -d --force-recreate app nginx
compose exec -T app php artisan migrate --force
compose exec -T app php artisan optimize:clear
compose exec -T app php artisan storage:link >/dev/null 2>&1 || true

if [[ "${TRIAL}" == true ]]; then
    compose stop queue scheduler
else
    compose up -d --force-recreate queue scheduler
fi

step "השחזור הושלם"
compose exec -T app php artisan app:move-summary || echo "יש לבדוק את ההודעות שלמעלה." >&2
echo "מתחברים עם המשתמשות והסיסמאות של השרת הישן."
if [[ "${TRIAL}" == true ]]; then
    echo "הרצת ניסיון: שליחת המיילים והמשימות המתוזמנות כבויה כאן. במעבר האמיתי — מריצים שוב בלי --trial."
fi
echo "אם כתובת האתר השתנתה: קישורים במיילים שכבר נשלחו מפנים לכתובת הישנה — כדאי להפנות גם אותה לשרת הזה."
