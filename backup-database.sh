#!/usr/bin/env bash

set -Eeuo pipefail

# Dumps hold every customer's personal data — readable by the owner only.
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_DIR="${SCRIPT_DIR}/backups"

mkdir -p "${BACKUP_DIR}"

# shellcheck disable=SC1090
set -a; source "${SCRIPT_DIR}/.env"; set +a

TIMESTAMP="$(date '+%Y-%m-%d_%H-%M-%S')"

BACKUP_FILE="${BACKUP_DIR}/crm_${TIMESTAMP}.dump"

cd "${SCRIPT_DIR}"

DOCKER=(docker)
if ! docker info >/dev/null 2>&1; then
    DOCKER=(sudo docker)
fi

"${DOCKER[@]}" compose exec -T postgres \
    pg_dump \
    -U "${DB_USERNAME}" \
    -d "${DB_DATABASE}" \
    -Fc \
    > "${BACKUP_FILE}"

find "${BACKUP_DIR}" \
    -type f \
    -name "*.dump" \
    -mtime +14 \
    -delete

echo "Backup created:"
echo "${BACKUP_FILE}"

# Uploaded files (storage/app: deal folders, supplier invoices, materials...)
# as a daily snapshot folder in backups/files/<timestamp>/. A file that didn't
# change since the previous snapshot is hard-linked to it, so 14 days of
# snapshots take about the space of one copy plus what changed.
FILES_DIR="${BACKUP_DIR}/files"
mkdir -p "${FILES_DIR}"
LATEST="$(find "${FILES_DIR}" -mindepth 1 -maxdepth 1 -type d -name '20*' | sort | tail -n 1)"
SNAPSHOT="${FILES_DIR}/${TIMESTAMP}"

rsync -a \
    --exclude='private/livewire-tmp/' \
    --exclude='private/tmp/' \
    ${LATEST:+--link-dest="${LATEST}"} \
    "${SCRIPT_DIR}/storage/app/" "${SNAPSHOT}.partial/"
mv "${SNAPSHOT}.partial" "${SNAPSHOT}"

find "${FILES_DIR}" -mindepth 1 -maxdepth 1 -type d -name '20*' -mtime +14 -exec rm -rf {} +
find "${FILES_DIR}" -mindepth 1 -maxdepth 1 -type d -name '*.partial' -mmin +360 -exec rm -rf {} +

echo "Files snapshot created:"
echo "${SNAPSHOT} ($(du -sh "${SNAPSHOT}" | cut -f1))"

# The weekly backup (App\Services\WeeklyBackup): every Sunday — or whenever
# the last one is over a week old — tonight's database dump, every uploaded
# file and APP_KEY in a regular password zip in backups/weekly/ (opens in
# Windows as is, restorable with move-import.sh) that downloads by itself to the user
# chosen in settings → גיבוי. Skipped (by the app) until a password is set.
WEEKLY_DIR="${BACKUP_DIR}/weekly"
mkdir -p "${WEEKLY_DIR}"
if [[ "$(date +%u)" == 7 ]] || ! find "${WEEKLY_DIR}" -maxdepth 1 -name 'kapaim-backup_*.zip' -mtime -7 | grep -q .; then
    VERSION="$(git -C "${SCRIPT_DIR}" rev-parse HEAD 2>/dev/null || echo unknown)"
    # The app container runs as the folder's owner (APP_UID), not as root.
    if [[ "$(id -u)" == 0 ]]; then
        chown "${APP_UID:-1001}:${APP_GID:-1001}" "${WEEKLY_DIR}" "${BACKUP_FILE}"
    fi
    "${DOCKER[@]}" compose exec -T app php artisan backup:weekly-package \
        "backups/$(basename "${BACKUP_FILE}")" --app-version="${VERSION}" \
        --repo="$(git -C "${SCRIPT_DIR}" remote get-url origin 2>/dev/null || true)"
fi
