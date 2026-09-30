#!/bin/bash
# Daily MySQL backup of the scm database (mysql8 container).
#   - mysqldump --single-transaction (consistent, no table locks for InnoDB), gzip
#   - keeps the newest $KEEP local copies in $DIR
#   - uploads each copy to s3://$BUCKET/scm/ (bucket lifecycle expires objects after 5 days)
# The root password is read inside the container from $MYSQL_ROOT_PASSWORD; it never touches the host.
# Cron: /etc/cron.d/scm-db-backup. Log: /var/log/scm-db-backup.log.
# `scm-db-backup.sh --test` dumps the schema only (no data) to check the whole pipeline quickly.
set -euo pipefail

CONTAINER=mysql8
DIR=/home/ubuntu/dbbackup/daily
KEEP=5
BUCKET=scm-db-backup-625240399201
MIN_FREE_GB=10
AWS=/usr/local/bin/aws

MODE=full
EXTRA_ARGS=""
if [ "${1:-}" = "--test" ]; then
    MODE=test
    EXTRA_ARGS="--no-data"
fi

exec 9>/run/scm-db-backup.lock
flock -n 9 || { echo "$(date -Is) another backup is running, exit"; exit 1; }

log() { echo "$(date -Is) [$MODE] $*"; }

mkdir -p "$DIR"
free_gb=$(df --output=avail -BG "$DIR" | tail -1 | tr -dc 0-9)
if [ "$free_gb" -lt "$MIN_FREE_GB" ]; then
    log "FAILED: only ${free_gb}G free (< ${MIN_FREE_GB}G)"
    exit 1
fi

stamp=$(TZ=Asia/Taipei date +%Y%m%d-%H%M)
name="scm-$stamp.sql.gz"
[ "$MODE" = test ] && name="scm-$stamp-schema-test.sql.gz"
file="$DIR/$name"

log "start -> $file"
start=$(date +%s)

docker exec "$CONTAINER" sh -c "exec mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" \
    --single-transaction --quick --routines --triggers --events --set-gtid-purged=OFF $EXTRA_ARGS \
    --databases \"\$MYSQL_DATABASE\"" 2> >(grep -v 'Using a password on the command line' >&2) \
    | nice -n 10 gzip -6 > "$file.part"

if ! zcat "$file.part" | tail -n 1 | grep -q '^-- Dump completed'; then
    rm -f "$file.part"
    log "FAILED: dump incomplete"
    exit 1
fi
mv "$file.part" "$file"
log "dump ok: $(du -h "$file" | cut -f1) in $(( $(date +%s) - start ))s"

if "$AWS" s3 cp "$file" "s3://$BUCKET/scm/$name" --only-show-errors; then
    log "uploaded s3://$BUCKET/scm/$name"
else
    log "FAILED: upload to S3 (local copy kept)"
    upload_failed=1
fi

if [ "$MODE" = test ]; then
    rm -f "$file"
else
    ls -1t "$DIR"/scm-*.sql.gz 2>/dev/null | grep -v schema-test | tail -n +$((KEEP + 1)) | while read -r old; do
        rm -f "$old" && log "pruned $old"
    done
fi

log "done"
[ -z "${upload_failed:-}" ]
