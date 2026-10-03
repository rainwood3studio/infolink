#!/bin/bash
# Scheduled Claude analysis for the INFOLINK ops hub (docs/03-integration.md §5, §6).
#
# Usage: scripts/claude-analysis.sh [--dry-run] <daily-brief|dev-activity-review|weekly-company-review|month-end-finance>
#
# Flow: lock → make sure the app is up (start Docker/compose if not) → fetch the MCP prompt text from the app
# (prompts/get, which also proves the MCP token works before any tokens are spent) → run `claude -p` inside the
# vault with a minimal tool allowlist → append the JSON result to ~/Library/Logs/infolink/<name>.log → macOS
# notification with a one-line status.
#
# Environment overrides (all optional):
#   INFOLINK_VAULT_WRITE=1        weekly-company-review may Write under 03.Business/INFOLINK/報告/ (default: off)
#   INFOLINK_TIMEOUT_SECONDS=900  hard limit for the claude run
#   INFOLINK_MAX_BUDGET_USD=      pass --max-budget-usd to claude (unset = no cap)
#   INFOLINK_MODEL=               pass --model to claude (unset = user's default)
#   INFOLINK_APP_WAIT_SECONDS=60  how long to wait for the app after `docker compose up -d`
#   INFOLINK_MCP_TOKEN=           "Bearer …" for prompts/get (default: read from the user-scope MCP config)

set -uo pipefail

# --- constants -------------------------------------------------------------------------------------------------

REPO_DIR="/Users/kenneth/Desktop/infolink"
VAULT_DIR="/Users/kenneth/Library/CloudStorage/OneDrive-聯騰資訊股份有限公司/2ndBrain"
APP_URL="http://127.0.0.1:8080"
MCP_URL="${APP_URL}/mcp/infolink"
LOG_DIR="${HOME}/Library/Logs/infolink"
PYTHON="/usr/bin/python3"
VAULT_REPORT_DIR="03.Business/INFOLINK/報告"

TIMEOUT_SECONDS="${INFOLINK_TIMEOUT_SECONDS:-900}"
APP_WAIT_SECONDS="${INFOLINK_APP_WAIT_SECONDS:-60}"

# launchd starts us with a minimal PATH: make sure claude, docker and gtimeout resolve.
export PATH="${HOME}/.local/bin:/opt/homebrew/bin:/usr/local/bin:/Applications/Docker.app/Contents/Resources/bin:/usr/bin:/bin:/usr/sbin:/sbin:${PATH:-}"
export LANG="${LANG:-en_US.UTF-8}"
export LC_ALL="${LC_ALL:-en_US.UTF-8}"

# --- arguments -------------------------------------------------------------------------------------------------

DRY_RUN=0
JOB=""
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
        daily-brief|dev-activity-review|weekly-company-review|month-end-finance) JOB="$arg" ;;
        *) echo "unknown argument: $arg" >&2; exit 2 ;;
    esac
done
if [[ -z "$JOB" ]]; then
    echo "usage: $0 [--dry-run] <daily-brief|dev-activity-review|weekly-company-review|month-end-finance>" >&2
    exit 2
fi

mkdir -p "$LOG_DIR"
LOG_FILE="${LOG_DIR}/${JOB}.log"

# --- helpers ---------------------------------------------------------------------------------------------------

log() {
    local line
    line="[$(date '+%Y-%m-%d %H:%M:%S')] [${JOB}] $*"
    echo "$line" >> "$LOG_FILE"
    [[ -t 2 || "$DRY_RUN" == 1 ]] && echo "$line" >&2
    return 0
}

notify() {
    # Pass text via argv so quotes/CJK never need escaping.
    local message="$1" subtitle="${2:-$JOB}"
    if [[ "$DRY_RUN" == 1 ]]; then
        log "(dry-run) would notify: ${subtitle} — ${message}"
        return 0
    fi
    /usr/bin/osascript \
        -e 'on run argv' \
        -e 'display notification (item 1 of argv) with title "INFOLINK" subtitle (item 2 of argv)' \
        -e 'end run' \
        "$message" "$subtitle" >/dev/null 2>&1 || true
}

fail() {
    log "ERROR: $1"
    notify "$1"
    exit "${2:-1}"
}

# Single-quote for display (printf %q escapes CJK bytes, which makes the dry run unreadable).
shq() {
    if [[ "$1" =~ ^[A-Za-z0-9_./=:-]+$ ]]; then printf '%s' "$1"; else printf "'%s'" "${1//\'/\'\\\'\'}"; fi
}

app_is_up() {
    curl -fsS -o /dev/null --max-time 5 "${APP_URL}/up" 2>/dev/null
}

# --- lock (no overlapping runs of the same job) ----------------------------------------------------------------

LOCK_DIR="${LOG_DIR}/.${JOB}.lock"
if ! mkdir "$LOCK_DIR" 2>/dev/null; then
    other_pid="$(cat "${LOCK_DIR}/pid" 2>/dev/null || true)"
    if [[ -n "$other_pid" ]] && kill -0 "$other_pid" 2>/dev/null; then
        log "another run is in progress (pid ${other_pid}); skipping"
        exit 0
    fi
    log "removing stale lock (pid ${other_pid:-unknown})"
    rm -rf "$LOCK_DIR"
    mkdir "$LOCK_DIR" 2>/dev/null || fail "無法取得執行鎖 ${LOCK_DIR}"
fi
echo $$ > "${LOCK_DIR}/pid"

WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/infolink-${JOB}.XXXXXX")"
# shellcheck disable=SC2329  # invoked via trap
cleanup() {
    rm -rf "$LOCK_DIR" "$WORK_DIR"
}
trap cleanup EXIT
trap 'log "interrupted"; exit 130' INT TERM

log "start (dry-run=${DRY_RUN})"

# --- 1. app up? ------------------------------------------------------------------------------------------------

if ! app_is_up; then
    log "app not responding at ${APP_URL}/up; trying docker compose up -d"
    if ! docker info >/dev/null 2>&1; then
        log "docker daemon not running; opening Docker Desktop"
        [[ "$DRY_RUN" == 1 ]] || open -ga Docker >/dev/null 2>&1 || true
    fi
    if [[ "$DRY_RUN" == 1 ]]; then
        log "(dry-run) would run: docker compose -f ${REPO_DIR}/docker-compose.yml up -d, then wait ${APP_WAIT_SECONDS}s"
    else
        # Docker Desktop may need a while before compose can talk to it.
        waited=0
        until docker info >/dev/null 2>&1 || (( waited >= APP_WAIT_SECONDS )); do sleep 5; waited=$((waited + 5)); done
        docker compose -f "${REPO_DIR}/docker-compose.yml" up -d >> "$LOG_FILE" 2>&1 || log "docker compose up -d failed"
        waited=0
        until app_is_up || (( waited >= APP_WAIT_SECONDS )); do sleep 5; waited=$((waited + 5)); done
    fi
    if ! app_is_up; then
        log "app still down"
        notify "INFOLINK app 未啟動，簡報未產生"
        exit 1
    fi
    log "app is up"
fi

# --- 2. prompt arguments ---------------------------------------------------------------------------------------

case "$JOB" in
    daily-brief)
        PROMPT_ARGS="{\"date\":\"$(date '+%Y-%m-%d')\"}"
        PERIOD_DESC="今天（$(date '+%Y-%m-%d')）"
        ;;
    dev-activity-review)
        PROMPT_ARGS="{\"date\":\"$(date '+%Y-%m-%d')\"}"
        PERIOD_DESC="前一個工作日與近 7 天（分析日 $(date '+%Y-%m-%d')）"
        ;;
    weekly-company-review)
        # Any day inside last week; the prompt resolves it to that week's Monday.
        PROMPT_ARGS="{\"week\":\"$(date -v-7d '+%Y-%m-%d')\"}"
        PERIOD_DESC="上週（含 $(date -v-7d '+%Y-%m-%d') 的那一週）"
        ;;
    month-end-finance)
        PROMPT_ARGS="{\"month\":\"$(date -v1d -v-1m '+%Y-%m')\"}"
        PERIOD_DESC="上個月（$(date -v1d -v-1m '+%Y-%m')）"
        ;;
esac

# --- 3. tool allowlist -----------------------------------------------------------------------------------------
#
# --tools restricts the *built-in* tools that exist at all (no Bash, Edit, WebFetch, Skill, Agent …), so broad
# allow rules in ~/.claude/settings.json cannot widen this run. --permission-mode dontAsk + --permission-prompts
# none deny anything not in --allowedTools (e.g. other MCP servers' tools) instead of waiting for a human.

BUILTIN_TOOLS="Read,Glob,Grep"
ALLOWED_TOOLS="mcp__infolink__*,Read,Glob,Grep"
VAULT_NOTE=""
if [[ "$JOB" == "weekly-company-review" ]]; then
    if [[ "${INFOLINK_VAULT_WRITE:-0}" == "1" ]]; then
        BUILTIN_TOOLS="${BUILTIN_TOOLS},Write"
        ALLOWED_TOOLS="${ALLOWED_TOOLS},Write(./${VAULT_REPORT_DIR}/**),Edit(./${VAULT_REPORT_DIR}/**)"
        VAULT_NOTE="vault 週報筆記只能寫在 \`${VAULT_REPORT_DIR}/\` 底下（例如 \`${VAULT_REPORT_DIR}/週報/<週一日期> 營運週報.md\`），其他路徑都不允許寫；save_report 的 vault_ref 填這個路徑。"
    else
        VAULT_NOTE="這次執行只允許讀 vault、不能寫檔：vault 週報筆記請不要嘗試寫入，改在最後回覆中附上完整 Markdown 與建議路徑（\`${VAULT_REPORT_DIR}/週報/…\`）；save_report 的 vault_ref 填建議路徑。"
    fi
fi

# --- 4. fetch the MCP prompt text from the app -----------------------------------------------------------------

MCP_AUTH="${INFOLINK_MCP_TOKEN:-}"
if [[ -z "$MCP_AUTH" ]]; then
    MCP_AUTH="$("$PYTHON" -c '
import json, os
d = json.load(open(os.path.expanduser("~/.claude.json")))
print(d["mcpServers"]["infolink"]["headers"]["Authorization"])
' 2>/dev/null || true)"
fi
[[ -n "$MCP_AUTH" ]] || fail "找不到 infolink MCP token（~/.claude.json 或 INFOLINK_MCP_TOKEN），簡報未產生"
[[ "$MCP_AUTH" == Bearer\ * ]] || MCP_AUTH="Bearer ${MCP_AUTH}"

REQUEST="{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"prompts/get\",\"params\":{\"name\":\"${JOB}\",\"arguments\":${PROMPT_ARGS}}}"
RESPONSE_FILE="${WORK_DIR}/prompt.json"
http_code="$(curl -sS --max-time 30 -o "$RESPONSE_FILE" -w '%{http_code}' "$MCP_URL" \
    -H "Authorization: ${MCP_AUTH}" \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json, text/event-stream' \
    --data "$REQUEST" 2>>"$LOG_FILE")" || http_code="curl-failed"
[[ "$http_code" == "200" ]] || fail "infolink MCP prompts/get 失敗（HTTP ${http_code}），簡報未產生"

PROMPT_BODY="$("$PYTHON" -c '
import json, sys
d = json.load(open(sys.argv[1]))
if "error" in d:
    sys.exit("mcp error: " + json.dumps(d["error"], ensure_ascii=False))
msgs = d["result"]["messages"]
print("\n\n".join(m["content"]["text"] for m in msgs if m.get("content", {}).get("type") == "text"))
' "$RESPONSE_FILE" 2>>"$LOG_FILE")" || fail "無法解析 ${JOB} prompt，簡報未產生"
[[ -n "$PROMPT_BODY" ]] || fail "${JOB} prompt 是空的，簡報未產生"

INSTRUCTION="這是 launchd 排程的無人值守執行，期間：${PERIOD_DESC}，沒有人會回答問題。以下是 infolink MCP 的 \`${JOB}\` prompt（由排程腳本透過 prompts/get 取得，引數 ${PROMPT_ARGS}）。請完整照著做，所有結論透過 infolink MCP 工具寫回 app，不要停下來問問題；工具被拒絕時就跳過並在報告與回覆中註明。${VAULT_NOTE}

最後的回覆第一行請用一句話（60 字內）總結，供 macOS 通知顯示。

---

${PROMPT_BODY}"

PROMPT_FILE="${WORK_DIR}/instruction.md"
printf '%s\n' "$INSTRUCTION" > "$PROMPT_FILE"

# --- 5. build the claude command -------------------------------------------------------------------------------

CLAUDE_BIN="$(command -v claude || true)"
[[ -n "$CLAUDE_BIN" ]] || fail "找不到 claude 指令（PATH=${PATH}），簡報未產生"

CLAUDE_CMD=(
    "$CLAUDE_BIN" -p
    --output-format json
    --tools "$BUILTIN_TOOLS"
    --allowedTools "$ALLOWED_TOOLS"
    --permission-mode dontAsk
    --permission-prompts none
    --no-session-persistence
)
[[ -n "${INFOLINK_MAX_BUDGET_USD:-}" ]] && CLAUDE_CMD+=(--max-budget-usd "$INFOLINK_MAX_BUDGET_USD")
[[ -n "${INFOLINK_MODEL:-}" ]] && CLAUDE_CMD+=(--model "$INFOLINK_MODEL")

TIMEOUT_BIN="$(command -v gtimeout || command -v timeout || true)"

if [[ "$DRY_RUN" == 1 ]]; then
    {
        echo "---- dry run: ${JOB} ----"
        echo "cwd:            ${VAULT_DIR}"
        echo "timeout:        ${TIMEOUT_SECONDS}s via ${TIMEOUT_BIN:-background watchdog}"
        echo "built-in tools: ${BUILTIN_TOOLS}"
        echo "allowedTools:   ${ALLOWED_TOOLS}"
        printf 'command:        cd %s && %s' "$(shq "$VAULT_DIR")" "${TIMEOUT_BIN:+$TIMEOUT_BIN -k 30 $TIMEOUT_SECONDS }"
        for word in "${CLAUDE_CMD[@]}"; do printf '%s ' "$(shq "$word")"; done
        printf '< %s\n' "$PROMPT_FILE"
        echo "prompt (stdin, $(wc -c < "$PROMPT_FILE" | tr -d ' ') bytes), first 25 lines:"
        head -n 25 "$PROMPT_FILE" | sed 's/^/  | /'
    } >&2
    log "dry run finished"
    exit 0
fi

# --- 6. run ----------------------------------------------------------------------------------------------------

cd "$VAULT_DIR" 2>/dev/null || fail "無法進入 vault（OneDrive 權限？），簡報未產生"

OUT_FILE="${WORK_DIR}/result.json"
ERR_FILE="${WORK_DIR}/stderr.log"
started=$(date +%s)
log "running claude (timeout ${TIMEOUT_SECONDS}s, tools: ${ALLOWED_TOOLS})"

if [[ -n "$TIMEOUT_BIN" ]]; then
    "$TIMEOUT_BIN" -k 30 "$TIMEOUT_SECONDS" "${CLAUDE_CMD[@]}" < "$PROMPT_FILE" > "$OUT_FILE" 2> "$ERR_FILE"
    status=$?
else
    "${CLAUDE_CMD[@]}" < "$PROMPT_FILE" > "$OUT_FILE" 2> "$ERR_FILE" &
    claude_pid=$!
    ( sleep "$TIMEOUT_SECONDS"; kill -TERM "$claude_pid" 2>/dev/null; sleep 30; kill -KILL "$claude_pid" 2>/dev/null ) &
    watchdog_pid=$!
    wait "$claude_pid"
    status=$?
    kill "$watchdog_pid" 2>/dev/null
    wait "$watchdog_pid" 2>/dev/null
    (( status == 143 || status == 137 )) && status=124
fi
elapsed=$(( $(date +%s) - started ))

{
    echo "----- $(date '+%Y-%m-%d %H:%M:%S') claude exit=${status} elapsed=${elapsed}s -----"
    cat "$OUT_FILE"
    echo
    if [[ -s "$ERR_FILE" ]]; then echo "----- stderr -----"; cat "$ERR_FILE"; fi
} >> "$LOG_FILE"

if (( status == 124 )); then
    fail "${JOB} 逾時（${TIMEOUT_SECONDS}s）被中止，請看 log"
fi

# --- 7. parse the JSON result and notify -----------------------------------------------------------------------

SUMMARY="$("$PYTHON" - "$OUT_FILE" <<'PY'
import json, sys
try:
    d = json.load(open(sys.argv[1]))
except Exception as e:
    print("ERR\t無法解析 claude 輸出：%s" % e)
    sys.exit(0)
if isinstance(d, list):  # defensive: take the final result message
    d = next((m for m in reversed(d) if isinstance(m, dict) and m.get("type") == "result"), {})
text = (d.get("result") or "").strip()
first = next((l.strip().lstrip("#").strip() for l in text.splitlines() if l.strip()), "")
cost = d.get("total_cost_usd")
suffix = " ($%.2f)" % cost if isinstance(cost, (int, float)) else ""
state = "ERR" if d.get("is_error") or d.get("subtype", "success") != "success" else "OK"
if state == "ERR" and not first:
    first = d.get("subtype") or "unknown error"
print("%s\t%s%s" % (state, first[:120], suffix))
PY
)"
state="${SUMMARY%%$'\t'*}"
line="${SUMMARY#*$'\t'}"

if [[ "$JOB" == "weekly-company-review" && "$state" == "OK" ]]; then
    # Keep the full reply (contains the vault note Markdown when vault writes are off).
    "$PYTHON" -c 'import json,sys;print(json.load(open(sys.argv[1])).get("result",""))' "$OUT_FILE" \
        > "${LOG_DIR}/${JOB}-$(date '+%Y-%m-%d').md" 2>/dev/null || true
fi

if (( status != 0 )) || [[ "$state" != "OK" ]]; then
    log "FAILED (exit ${status}): ${line}"
    notify "失敗：${line}"
    exit 1
fi

log "done in ${elapsed}s: ${line}"
notify "完成：${line}"
exit 0
