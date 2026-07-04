#!/bin/bash
# =============================================================================
# POS SaaS — Automated Database Backup Script
# Runs daily via cron. Backs up central + all tenant DBs to S3-compatible storage.
# =============================================================================

set -euo pipefail

# --- Config (loaded from .env or environment) ---
MYSQL_USER=${MYSQL_USER:-root}
MYSQL_PASS=${MYSQL_PASS:-}
MYSQL_HOST=${MYSQL_HOST:-127.0.0.1}
CENTRAL_DB=${CENTRAL_DB:-pos_system}
TENANT_DB_PREFIX=${TENANT_DB_PREFIX:-pos_store_}

S3_BUCKET=${BACKUP_S3_BUCKET:-}
S3_ENDPOINT=${BACKUP_S3_ENDPOINT:-}
S3_KEY=${BACKUP_S3_KEY:-}
S3_SECRET=${BACKUP_S3_SECRET:-}
BACKUP_RETENTION_DAYS=${BACKUP_RETENTION_DAYS:-30}

BACKUP_DIR=/tmp/pos-backups
TIMESTAMP=$(date +%Y-%m-%d-%H%M)
LOG_FILE=/var/log/pos-backup.log

# Notification email (optional)
NOTIFY_EMAIL=${BACKUP_NOTIFY_EMAIL:-}

log() { echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] $1" | tee -a "$LOG_FILE"; }
fail() { log "ERROR: $1"; [ -n "$NOTIFY_EMAIL" ] && echo "Backup FAILED: $1" | mail -s "POS Backup Failure" "$NOTIFY_EMAIL"; exit 1; }

log "=== POS Backup started ==="

# Create temp directory
mkdir -p "$BACKUP_DIR"

# --- Helper: dump + upload one database ---
backup_database() {
    local db=$1
    local filename="pos-backup-${db}-${TIMESTAMP}.sql.gz"
    local filepath="$BACKUP_DIR/$filename"

    log "Backing up: $db"

    mysqldump \
        -h "$MYSQL_HOST" -u "$MYSQL_USER" ${MYSQL_PASS:+-p"$MYSQL_PASS"} \
        --single-transaction \
        --routines \
        --triggers \
        --set-gtid-purged=OFF \
        "$db" | gzip > "$filepath" \
        || fail "mysqldump failed for $db"

    local size=$(du -sh "$filepath" | cut -f1)
    log "  Compressed: $size — $filename"

    # Upload to S3
    if [ -n "$S3_BUCKET" ]; then
        AWS_ACCESS_KEY_ID="$S3_KEY" \
        AWS_SECRET_ACCESS_KEY="$S3_SECRET" \
        aws s3 cp "$filepath" "s3://${S3_BUCKET}/${filename}" \
            ${S3_ENDPOINT:+--endpoint-url "$S3_ENDPOINT"} \
            || fail "S3 upload failed for $db"
        log "  Uploaded to s3://${S3_BUCKET}/${filename}"
    else
        log "  S3 not configured — backup kept locally only at $filepath"
    fi

    rm -f "$filepath"
}

# --- Backup central DB ---
backup_database "$CENTRAL_DB"

# --- Backup all tenant DBs ---
TENANT_DBS=$(mysql -h "$MYSQL_HOST" -u "$MYSQL_USER" ${MYSQL_PASS:+-p"$MYSQL_PASS"} \
    -e "SHOW DATABASES LIKE '${TENANT_DB_PREFIX}%';" -s --skip-column-names 2>/dev/null)

for db in $TENANT_DBS; do
    backup_database "$db"
done

# --- Clean up old backups from S3 (> RETENTION_DAYS) ---
if [ -n "$S3_BUCKET" ]; then
    log "Cleaning up backups older than ${BACKUP_RETENTION_DAYS} days..."
    CUTOFF=$(date -d "-${BACKUP_RETENTION_DAYS} days" +%Y-%m-%d 2>/dev/null || date -v-${BACKUP_RETENTION_DAYS}d +%Y-%m-%d)

    AWS_ACCESS_KEY_ID="$S3_KEY" \
    AWS_SECRET_ACCESS_KEY="$S3_SECRET" \
    aws s3 ls "s3://${S3_BUCKET}/" ${S3_ENDPOINT:+--endpoint-url "$S3_ENDPOINT"} \
        | grep "pos-backup-" \
        | while read -r line; do
            file_date=$(echo "$line" | awk '{print $1}')
            file_name=$(echo "$line" | awk '{print $4}')
            if [[ "$file_date" < "$CUTOFF" ]]; then
                log "  Deleting old backup: $file_name"
                AWS_ACCESS_KEY_ID="$S3_KEY" AWS_SECRET_ACCESS_KEY="$S3_SECRET" \
                aws s3 rm "s3://${S3_BUCKET}/${file_name}" ${S3_ENDPOINT:+--endpoint-url "$S3_ENDPOINT"}
            fi
        done
fi

log "=== POS Backup completed successfully ==="

# Notify success
[ -n "$NOTIFY_EMAIL" ] && echo "Backup completed successfully at $(date)" | mail -s "POS Backup OK" "$NOTIFY_EMAIL"

exit 0
