#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_DIR="${SCRIPT_DIR}/backups"

mkdir -p "${BACKUP_DIR}"

# shellcheck disable=SC1090
set -a; source "${SCRIPT_DIR}/.env"; set +a

TIMESTAMP="$(date '+%Y-%m-%d_%H-%M-%S')"

BACKUP_FILE="${BACKUP_DIR}/crm_${TIMESTAMP}.dump"

cd "${SCRIPT_DIR}"

docker compose exec -T postgres \
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
