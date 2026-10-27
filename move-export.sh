#!/usr/bin/env bash
#
# Packs everything a new server needs to carry on from this one — the
# database, the uploaded files (storage/app) and the encryption key
# (APP_KEY: the SUMIT keys are encrypted with it, and links in emails
# already sent are signed with it) — into one file. Full steps: DEPLOY.md,
# section 9. On the new server: ./move-import.sh <file>.
#
#   ./move-export.sh                 stops sending emails/tasks here first (the move itself)
#   ./move-export.sh --keep-running  a trial run — this server keeps working as usual
#
# The file holds every customer's data and the encryption key: keep it
# private and delete it once the move is done.

set -Eeuo pipefail
umask 077

cd "$(dirname "${BASH_SOURCE[0]}")"

step() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

DOCKER=(docker)
if ! docker info >/dev/null 2>&1; then
    DOCKER=(sudo docker)
fi
compose() { "${DOCKER[@]}" compose "$@"; }

KEEP_RUNNING=false
[[ "${1:-}" == "--keep-running" ]] && KEEP_RUNNING=true

# shellcheck disable=SC1091
set -a; source .env; set +a

if [[ "${KEEP_RUNNING}" == false ]]; then
    # From here on only the new server sends — no reminder or queued email goes out twice.
    step "עצירת שליחת המיילים והמשימות המתוזמנות בשרת הזה"
    compose stop queue scheduler
fi

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

step "גיבוי מסד הנתונים"
compose exec -T postgres pg_dump -U "${DB_USERNAME}" -d "${DB_DATABASE}" -Fc > "${WORK}/database.dump"

step "אריזת הקבצים שהועלו (storage/app)"
tar -C storage --exclude='app/livewire-tmp' --exclude='app/tmp' -cf "${WORK}/storage-app.tar" app

grep '^APP_KEY=' .env > "${WORK}/app-key.env"
git rev-parse HEAD > "${WORK}/version.txt" 2>/dev/null || echo unknown > "${WORK}/version.txt"

mkdir -p backups
OUT="backups/kapaim-move_$(date '+%Y-%m-%d_%H-%M-%S').tar.gz"
tar -C "${WORK}" -czf "${OUT}" .

step "הקובץ מוכן"
echo "$(pwd)/${OUT} ($(du -h "${OUT}" | cut -f1))"
echo "מעבירים אותו לשרת החדש (למשל: scp \"$(pwd)/${OUT}\" user@new-server:~/) ומריצים שם: ./move-import.sh <הקובץ>"
if [[ "${KEEP_RUNNING}" == false ]]; then
    echo "השליחה בשרת הזה עצורה. להחזרה (אם המעבר בוטל): docker compose start queue scheduler"
fi
