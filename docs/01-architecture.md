# 01 系統架構

## 全貌

```
                         ┌──────────────────────────── 本機 Mac ─────────────────────────────┐
                         │                                                                    │
  Obsidian 2ndBrain ─────┼──(唯讀掛載 /vault)──┐                                              │
  (OneDrive)             │                     ▼                                              │
                         │   ┌────────────── Docker Compose：infolink ──────────────┐        │
  Redmine 10.100.66.47 ◀─┼───┤ app        Laravel 13 + Filament 5（http://127.0.0.1:8080）│        │
  (公司網路／VPN)         │   │            ├─ Filament 後台（儀表板、CRUD、手動輸入）   │        │
                         │   │            ├─ MCP Server  /mcp/infolink  ◀───────────┼──┐     │
                         │   │            └─ REST API    /api/v1/*                  │  │     │
                         │   │ scheduler  schedule:work（同步、快照、規則、通知）     │  │     │
                         │   │ queue      queue:work（LINE 推播、長任務）             │  │     │
                         │   │ db         PostgreSQL 17（volume 持久化）               │  │     │
                         │   └──────────────────────────────────────────────────────┘  │     │
                         │                                                             │     │
                         │   Claude Code CLI ──────────────(MCP over HTTP)─────────────┘     │
                         │   ├─ 互動：在 2ndBrain vault 裡對話（讀筆記 → 寫進 app）           │
                         │   ├─ skills：redmine、infolink（新）                              │
                         │   └─ launchd 排程：每日簡報 / 每週報告（claude -p headless）       │
                         └──────────────────────────────────────────────────────────────────┘
                                          │
                                          ▼
                              LINE Messaging API（推播到手機）
```

## Docker Compose 組成

| service | image／內容 | 說明 |
| --- | --- | --- |
| `app` | PHP 8.5 FPM + Nginx（例如 `serversideup/php` 的 fpm-nginx 變體，實作時確認 tag） | Web、Filament、MCP、API。port `127.0.0.1:8080:8080`，不對外開 |
| `scheduler` | 同 `app` image | `php artisan schedule:work` |
| `queue` | 同 `app` image | `php artisan queue:work --tries=3`（通知、同步可重試） |
| `db` | `postgres:17` | volume `pgdata`；port `127.0.0.1:5432`，方便本機 Claude／TablePlus 直連 |

不使用 Octane／FrankenPHP worker mode：單人使用，FPM 足夠且除錯簡單（參考 vault 筆記《Laravel Octane (FrankenPHP) vs. PHP-FPM》的結論）。

**掛載**

```yaml
volumes:
  - ./:/var/www/html
  - "${VAULT_PATH}:/vault:ro"      # 2ndBrain，唯讀
```

`VAULT_PATH` 放 `.env`，值為 `/Users/kenneth/Library/CloudStorage/OneDrive-聯騰資訊股份有限公司/2ndBrain`（路徑含空白與 CJK，compose 內要加引號）。

**連 Redmine**：Docker Desktop for Mac 的容器對外流量走主機網路，主機在公司網路／VPN 時就能連到 `10.100.66.47:8090`。不在時同步會失敗，記進 `sync_runs`，**不發警報**（除非連續 24 小時失敗）。

## 資料的「真相來源」

同一份資料只能有一個地方是權威，其他地方都是副本，避免兩邊各改各的。

| 資料 | 真相來源 | app 的角色 | 說明 |
| --- | --- | --- | --- |
| Redmine 議題、工時 | Redmine | 唯讀鏡像＋每日快照 | app 不回寫 Redmine；要改議題用 redmine skill |
| 銀行交易 | 銀行明細（PDF／CSV） | **權威**（匯入後） | vault 的「帳戶流水」筆記改成從 app 產生的解讀，數字以 app 為準 |
| 應收、收款時程 | **app** | 權威 | 目前在《2026 下半年現金推估》的表格，遷移後以 app 為準 |
| 現金推估 | **app**（由應收＋月成本計算） | 權威＋快照 | 每次重算存一筆快照，可比較「推估準不準」 |
| 業務機會、客戶 | **app** | 權威 | |
| 指標數值 | **app** | 權威 | 由同步、計算、Claude、手動寫入 |
| 待辦（app 內建立的） | **app** | 權威 | |
| 待辦（vault 裡的 `- [ ]`） | vault | 唯讀鏡像 | 在 vault 勾掉，app 下次掃描同步狀態 |
| 敘事、脈絡、決策紀錄 | vault | 只存連結 | app 的紀錄都可附 `vault_ref`（筆記路徑），點了用 `obsidian://` 開啟 |
| 報告（週報、簡報） | **app**＋vault 副本 | 權威 | Claude 同時寫進 app 與 vault（vault 那份給 Obsidian 連結用） |

## 程式分層

```
app/
├─ Domain/                     # 業務邏輯，所有寫入的唯一入口
│  ├─ Metrics/   MetricRecorder, MetricCalculator
│  ├─ Finance/   TransactionImporter, ReceivableService, CashForecaster
│  ├─ Sales/     DealService
│  ├─ Delivery/  RedmineSync, RedmineSnapshotter, DeliveryMetrics
│  ├─ Work/      ActionItemService, VaultTaskScanner
│  ├─ Insights/  InsightService（含 fingerprint 去重）, RuleEvaluator
│  └─ Notify/    Notifier（channel：database / line / macos）
├─ Filament/                   # 後台：Resources、Pages、Widgets（只呼叫 Domain）
├─ Mcp/                        # MCP Server 與 Tools（只呼叫 Domain）
├─ Http/Controllers/Api/V1/    # REST（只呼叫 Domain）
└─ Console/Commands/           # infolink:sync-redmine、infolink:snapshot、infolink:evaluate-rules…
```

Filament、MCP、REST、Artisan 指令四個入口共用 `Domain/`，所以「Claude 寫進來的」和「我手動在後台填的」會走相同的驗證與副作用（觸發規則、記錄來源）。

## 安全

- 只綁 `127.0.0.1`；不做對外部署。若日後要在手機看，走 Tailscale 等私有網路，不開 port forwarding。
- Filament：單一帳號＋密碼（可再加 2FA）。
- MCP／API：Sanctum personal access token，**每個呼叫者一把**（`claude-cli`、`vault-agent`、`launchd-brief`），token 名稱會記在每筆寫入的 `actor` 欄位，事後查得到是誰寫的。token 分 `read` / `write` 權限。
- Claude 的 ad-hoc SQL 用 PostgreSQL 唯讀角色 `infolink_ro`，只能 `SELECT` 報表用的 view。
- 機密（Redmine API key、LINE token）只放 `.env`，`.env` 不進 git。
- 備份：獨立的 `backup` 容器（postgres:17）每天 `pg_dump` 到 `~/Backups/infolink/`，保留 30 天。財務資料不建議放進 OneDrive 的 vault 資料夾。
