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
