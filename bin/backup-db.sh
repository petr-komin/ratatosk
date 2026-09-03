#!/usr/bin/env bash
#
# Ratatosk — denní záloha databáze. Běží na HOSTU (ne v kontejneru), protože
# tam běží i samotný Postgres a má tam nainstalovaný pg_dump.
#
#   ./bin/backup-db.sh
#
# Cron (nainstaluje deploy.sh, nebo ručně):
#   30 3 * * * /home/manx/ratatosk/bin/backup-db.sh >> /home/manx/ratatosk/backup.log 2>&1

set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="${BACKUP_DIR:-$DIR/backups}"
KEEP_DAYS="${KEEP_DAYS:-30}"

set -a
# shellcheck source=/dev/null
source "$DIR/.env"
set +a

# .env má DB_HOST=host.docker.internal pro kontejner; ten se z hosta
# samotného nepřeloží. Zálohu bereme přímo přes loopback.
DB_HOST_LOCAL="${BACKUP_DB_HOST:-127.0.0.1}"

mkdir -p "$BACKUP_DIR"
STAMP=$(date +%Y%m%d-%H%M%S)
FILE="$BACKUP_DIR/ratatosk-$STAMP.sql.gz"

PGPASSWORD="$DB_PASSWORD" pg_dump \
    -h "$DB_HOST_LOCAL" -p "${DB_PORT:-5432}" -U "$DB_USER" "$DB_NAME" \
    | gzip > "$FILE"

if [ ! -s "$FILE" ]; then
    echo "[$(date '+%F %T')] záloha vyšla prázdná, mažu: $FILE" >&2
    rm -f "$FILE"
    exit 1
fi

echo "[$(date '+%F %T')] záloha OK: $FILE ($(du -h "$FILE" | cut -f1))"

find "$BACKUP_DIR" -name 'ratatosk-*.sql.gz' -mtime "+$KEEP_DAYS" -delete
