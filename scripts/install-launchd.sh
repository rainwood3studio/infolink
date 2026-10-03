#!/bin/bash
# Install (or remove) the INFOLINK scheduled-analysis launchd agents (docs/03-integration.md §5).
#
# Usage: scripts/install-launchd.sh             install / reinstall all agents (idempotent)
#        scripts/install-launchd.sh --uninstall  unload and remove them
#
# Agents: tw.infolink.daily-brief (Mon–Fri 08:30), tw.infolink.dev-review (Mon–Fri 08:45),
#         tw.infolink.weekly-review (Mon 09:00),
#         tw.infolink.month-end (day 2, 09:00). All call scripts/claude-analysis.sh.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC_DIR="${SCRIPT_DIR}/launchd"
AGENTS_DIR="${HOME}/Library/LaunchAgents"
LOG_DIR="${HOME}/Library/Logs/infolink"
DOMAIN="gui/$(id -u)"
LABELS=(tw.infolink.daily-brief tw.infolink.dev-review tw.infolink.weekly-review tw.infolink.month-end)

bootout() {
    local label="$1"
    if launchctl print "${DOMAIN}/${label}" >/dev/null 2>&1; then
        launchctl bootout "${DOMAIN}/${label}" 2>/dev/null || true
        echo "unloaded ${label}"
    fi
}

case "${1:-}" in
    --uninstall)
        for label in "${LABELS[@]}"; do
            bootout "$label"
            rm -f "${AGENTS_DIR}/${label}.plist"
        done
        echo "removed. Logs kept in ${LOG_DIR}"
        exit 0
        ;;
    "") ;;
    *) echo "usage: $0 [--uninstall]" >&2; exit 2 ;;
esac

mkdir -p "$AGENTS_DIR" "$LOG_DIR"
chmod +x "${SCRIPT_DIR}/claude-analysis.sh"

for label in "${LABELS[@]}"; do
    src="${SRC_DIR}/${label}.plist"
    dst="${AGENTS_DIR}/${label}.plist"
    plutil -lint "$src" >/dev/null
    bootout "$label"
    install -m 0644 "$src" "$dst"
    launchctl bootstrap "$DOMAIN" "$dst"
    echo "loaded ${label}"
done

echo
echo "Installed. Check with: launchctl print ${DOMAIN}/tw.infolink.daily-brief | grep -E 'state|last exit'"
echo "Run once now:          launchctl kickstart ${DOMAIN}/tw.infolink.daily-brief"
echo "Logs:                  ${LOG_DIR}"
