#!/bin/bash
# Rotate the logs that live inside the scm-app1 container, from the host (the container has no logrotate).
# Paths are resolved through the container's overlay "merged" dir at run time, so nothing inside the container
# changes and it never needs a restart. copytruncate keeps nginx / Laravel / redis writing to the same file.
# Cron: /etc/cron.d/scm-app-logrotate (hourly, so maxsize can kick in). Extra args go to logrotate (e.g. -d).
set -euo pipefail

CONTAINER=scm-app1
STATE=/var/lib/logrotate/scm-app1.status

merged=$(docker inspect -f '{{.GraphDriver.Data.MergedDir}}' "$CONTAINER" 2>/dev/null) || { echo "$(date -Is) $CONTAINER not found, skip"; exit 0; }
[ -d "$merged" ] || { echo "$(date -Is) $CONTAINER not running, skip"; exit 0; }

conf=$(mktemp)
trap 'rm -f "$conf"' EXIT

cat > "$conf" <<EOF
$merged/var/log/nginx/access.log
$merged/var/log/nginx/error.log
$merged/var/www/scm-api/storage/logs/laravel.log
{
    su root root
    daily
    maxsize 200M
    rotate 14
    dateext
    dateformat -%Y%m%d-%H
    compress
    delaycompress
    copytruncate
    missingok
    notifempty
}

$merged/var/log/redis/redis-server.log
{
    su root root
    weekly
    maxsize 200M
    rotate 4
    dateext
    dateformat -%Y%m%d-%H
    compress
    copytruncate
    missingok
    notifempty
}
EOF

logrotate -s "$STATE" "$@" "$conf"
