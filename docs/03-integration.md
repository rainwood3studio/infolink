# 03 整合：Claude、Redmine、2ndBrain、通知

這份是整個規劃的重點：**讓 Claude 讀寫這個 app，跟讀寫一個本機檔案一樣順手。**

## 設計原則

1. **一個介面給所有 Claude**：Claude CLI 互動、vault agent、Redmine skill、排程簡報，全部透過同一個 MCP server 讀寫。不要讓每個 skill 各自拼 HTTP 或寫 SQL。
2. **工具以「業務動作」為單位，不是以資料表為單位**：提供 `record_receivable_payment`，而不是 `update_table(receivables)`。Claude 不用懂 schema，也不會寫壞資料。
3. **寫入一律冪等**：每個寫入工具都接受 `external_key`（或自動由內容雜湊產生），Claude 重跑同一份分析不會產生重複資料。
4. **讀取先給摘要**：`get_briefing` 一次回傳 Claude 做判斷需要的全部脈絡，避免 Claude 呼叫十幾次工具才拼得出全貌。
5. **通知是 app 的責任**：Claude 只要把 insight 標成 `critical` 或在存報告時設定 `notify: true`，要不要推、推到哪、有沒有重複，由 app 決定。Claude 不需要知道 LINE token。

---

## 1. MCP Server（主要介面）

- 套件：`laravel/mcp`（已安裝 v1.0.1）
- 路由：`routes/ai.php` → `Mcp::web('/mcp/infolink', InfolinkServer::class)->middleware('auth:sanctum')`
- 端點：`http://127.0.0.1:8080/mcp/infolink`

### 註冊到 Claude CLI（user scope，任何資料夾都能用）

```bash
claude mcp add --transport http --scope user infolink \
  http://127.0.0.1:8080/mcp/infolink \
  --header "Authorization: Bearer <claude-cli 的 token>"
```

Token 在 Filament「設定 → API Tokens」產生，每個呼叫者一把（`claude-cli`、`launchd-brief`），分 `read` / `write` 權限。

### 工具清單

**讀取（read 權限）**

| 工具 | 用途 | 回傳重點 |
| --- | --- | --- |
| `get_briefing` | **分析的起手式**。一次拿到公司現況 | 釘選指標最新值與變化、超門檻的指標、open insights、逾期／近期待辦、未收應收、最新現金推估、資料新鮮度（各同步最後成功時間） |
| `query_metrics` | 查指標歷史 | 參數：`keys[]`、`from`、`to`、`dimension`；回傳時間序列 |
| `list_metric_definitions` | 查有哪些指標、定義與門檻 | 含 `description`，讓 Claude 正確解讀 |
| `list_receivables` | 應收清單 | 可篩 `status`、`customer`、`overdue_only` |
| `get_cash_position` | 現金部位 | 最新餘額、常態月成本、最新推估、與上一版推估的差異 |
| `list_deals` | 業務機會 | 依階段；標出沒有下一步的 |
| `redmine_summary` | 交付面摘要（讀 app 的鏡像，不打 Redmine） | 存量、本週流入／流出、驗證中依 assignee 拆分、停滯、逾期、未指派；各專案排行 |
| `closing_projects` | 結案中專案的作戰板 | 每案未結議題依「卡在誰」分四段（未指派／開發中／驗證中‧非驗收者／等驗收）、掛著的未收應收、14 天走勢、近期每日淨消化與推估結案日 |
| `acceptance_queue` | 驗收隊列 | 驗收者名下的驗證中議題：依專案與等待天數、每週驗收 vs 送驗 vs 驗收者 commit 數、清空週數、建議驗收順序、流程外清單 |
| `revenue_outlook` | 未來 12 個月收入展望 | 每月專案應收／經常性收入／假設續約／加權業務機會／成本、三條月底餘額線、每月缺口、現金高點與歸零月份；可帶 `delay_months`（尾款延後）與 `include_low_confidence`；點名缺金額／成交日的業務機會 |
| `dev_activity_summary` | GitHub 開發活動＋每人 Redmine 產出 | 每人 commit／議題／送驗／驗收，近 7 天對比前 7 天，每日 commit 標題 |
| `project_pnl` | 專案損益與人力投入（近 7–31 天，預設 30） | 每專案／客戶的投入人天（一人一天有 commit 算一個人天，當天碰幾個專案就平分）、估算成本（月成本依人天比例攤，只是估算）、期間收入與每月維運費、估算差額、合約金額、已收／未收、Redmine 工時；尚未簽約的投入（指定給業務機會的 repo）；沒對應到專案的 repo；缺合約金額／Redmine 連結的專案 |
| `list_insights` / `list_action_items` | 查現有洞察與待辦 | 預設只列 open，**Claude 寫新的之前先查，避免重複** |
| `get_report` / `list_reports` | 讀過去的報告 | 寫週報時比較上週 |
| `run_readonly_sql` | ad-hoc 分析 | 用 `infolink_ro` 角色，只能讀 `v_*` view，限時 10 秒、最多 500 列 |

**寫入（write 權限）**

| 工具 | 用途 | 冪等鍵 |
| --- | --- | --- |
| `record_metrics` | 批次寫指標值（`[{key, period_start, dimension?, value, notes?}]`） | `metric_key+period_start+dimension` |
| `import_bank_transactions` | 批次匯入交易（Claude 從 PDF／明細解析後送入）；回報餘額連續性檢查結果 | 交易內容雜湊 |
| `upsert_receivable` | 新增或修改應收節點 | `external_key` 或 `customer+project+item` |
| `record_receivable_payment` | 把應收標成已收，並連到那筆入帳交易 | receivable id |
| `save_cash_forecast` | 存一版現金推估快照（可由 app 自動計算，也可由 Claude 帶入假設） | `as_of` + `label` |
| `upsert_deal` / `log_deal_event` | 業務機會與互動紀錄 | `external_key` |
| `raise_insight` | 建立或更新一個注意事項 | `fingerprint`（同一個還沒解決就只更新） |
| `resolve_insight` | 結案一個 insight，附原因 | id 或 fingerprint |
| `create_action_item` / `update_action_item` | 待辦 | `external_key` |
| `save_report` | 存報告（Markdown＋引用的指標快照）；`notify: true` 會推播摘要 | `type+period_start` |

**MCP Prompts**（在 Claude 裡可直接呼叫的範本）：`daily-brief`、`weekly-company-review`、`month-end-finance`。內容就是下面「排程分析」的流程說明，放在 server 端，改一處所有地方都更新。

### 寫入範例（Claude 端的一次財務更新）

使用者在 vault 裡說：「9 月的彰銀明細進來了，幫我更新」→ Claude：

1. 讀 PDF，解析交易 → `import_bank_transactions`（app 回報：15 筆新增、餘額連續性 OK）
2. 發現我識 860,000 入帳 → `record_receivable_payment`（我識期中款，連到該筆交易）
3. `save_cash_forecast`（新基準 09/22、餘額 1,072,776）
4. 長照期中款 9 月底到期未收 → `raise_insight`（`fingerprint: receivable-due:長照-期中款`，warning）
5. 照舊在 vault 寫《帳戶流水 2026-09》筆記，加上 app 連結

---

## 2. `infolink` skill（新增，放 `~/.claude/skills/infolink/`）

MCP 提供「能做什麼」，skill 提供「什麼時候、怎麼做」。SKILL.md 內容大綱：

- **何時用**：提到公司營運、財務、現金、應收、業務、儀表板、待辦、注意事項；或任何分析做完、產生了數字或結論的時候。
- **寫回規則**（最重要）：
  - 分析產生的**數字** → `record_metrics` 或對應的業務工具，不要只寫在筆記裡。
  - 發現的**風險／異常** → 先 `list_insights` 查有沒有同一件事，再 `raise_insight`，固定使用下表的 fingerprint 命名規則。
  - 需要有人**動手做的事** → `create_action_item`，並連回 insight。
  - 完整的**分析文字** → `save_report`，同時照原本習慣寫進 vault，兩邊互相連結。
- **fingerprint 命名規則**：`<類別>-<對象>:<識別>`，例如 `receivable-overdue:長照-期中款`、`delivery-stalled:tcsb-5f-b2c`、`cash-low:2026-11`。
- **不要做的事**：不刪資料（只能 resolve／dropped）；金額不確定時用 `confidence: low` 並在 `notes` 說明，不要猜。

同時在 **2ndBrain 的 `CLAUDE.md`** 加一段：「分析公司營運時，結論要透過 infolink MCP 寫回 app」，讓在 Obsidian 裡（claudian 外掛）跑的 Claude 也照做。

---

## 3. Redmine

### app 端：定時同步（不依賴 Claude）

| 排程 | 指令 | 內容 |
| --- | --- | --- |
| 每小時（工作日 8–20 點） | `infolink:sync-redmine` | 用 `updated_on>=上次同步時間` 增量拉議題與工時，upsert 到 `redmine_issues` / `redmine_time_entries` |
| 每天 23:50 | `infolink:snapshot-redmine` | 寫一筆 `redmine_status_snapshots`，並計算交付指標寫進 `metric_values` |
| 每週日 03:00 | `infolink:sync-redmine --full` | 全量比對，補回被刪或搬移的議題 |

- 連線資訊讀 `.env`：`REDMINE_URL`、`REDMINE_API_KEY`（跟 `~/.claude/skills/redmine/config.env` 同一組）。
- 狀態、追蹤標籤的名稱對照參考 `~/.claude/skills/redmine/references/metadata.md`，實作時做成 seeder。
- **第一次上線時**：全量拉 2,791 筆議題與 1,893 筆工時。歷史快照無法回推，但可以用 `created_on` / `closed_on` 重建「每日存量」的近似值作為起點。

### Redmine skill 端：分析與寫回

現有的週報流程（`03.Business/INFOLINK/Redmine週報/Redmine 週報.md` 的「產生方式」）改成：

1. **取數改讀 app**：`redmine_summary` + `query_metrics`，不用再每次分頁撈 601 筆 open 議題（app 已經有了）。需要議題細節時才用 `redmine.py issue <id>`。
2. 照原本的「固定看的四件事」寫分析。
3. 寫回：`save_report(type: weekly_redmine)`＋每個風險 `raise_insight`（例：`delivery-offflow:tcsb-5f-b2c` — 驗證中掛在非文豪名下 58 筆）。
4. vault 照樣存一份週報 Markdown。

在 redmine skill 的 SKILL.md 加一行：「若 infolink MCP 可用，彙總數字優先讀 app，分析結論要寫回 app。」

---

## 4. 2ndBrain vault

vault 唯讀掛在容器的 `/vault`。

### 待辦掃描（確定性，不用 AI）

> **未實作，已決定不做**（2026-10，見 07 管理路線圖 B4）：vault 裡只有 5 個未勾選的 checkbox，沒有日期，沒有東西可同步。以下為原始設計，僅供參考。

`infolink:scan-vault-tasks`，每 15 分鐘執行：

- 範圍：`03.Business/**`、`01.Inbox/**`（可在設定調整）
- 抓 Obsidian Tasks 格式的 `- [ ]` / `- [x]`，解析 `📅 YYYY-MM-DD`（到期）、`⏫`/`🔼`（優先）、`#標籤`
- `external_key = sha1(檔案路徑 + 去掉勾選狀態的行內容)`；vault 勾掉 → app 同步成 `done`；行被刪 → `dropped`
- 在 app 裡點待辦可用 `obsidian://open?vault=2ndBrain&file=...` 跳回原筆記

**單向**：vault 的待辦在 vault 改。app 內新增的待辦只存在 app，不寫回 vault。

### 非結構化內容（交給 Claude）

財務推估、提案、會議紀錄這類敘事型筆記**不寫 parser 去解析**（格式會變，parser 會悄悄壞掉），而是由 Claude 讀懂後透過 MCP 寫入。第一次上線時用一次性 prompt 把現有筆記的數字灌進去（見 05 路線圖 Phase 1）。

---

## 5. 排程分析：讓 Claude 主動報告

AI 分析跑在**主機**上（launchd），不在容器裡：Claude CLI 的登入與設定都在主機，且這樣可以同時讀 vault 與呼叫 MCP。

| 排程 | 時間 | 做什麼 |
| --- | --- | --- |
| 每日簡報 | 週一～五 08:30 | `get_briefing` → 找出今天要注意的 3–5 件事 → `raise_insight` / `create_action_item` → `save_report(type: daily_brief, notify: true)` |
| 開發活動分析 | 週一～五 08:45 | `dev_activity_summary`（前一個工作日＋近 7 天，含前 7 天比較）→ 每人做了什麼、趨勢與 2–4 點建議 → `save_report(type: dev_review)`；顯示在「交付 → 開發活動」頁最上方 |
| AI 顧問分析 | 週一～五 08:55 | 六個面向（現金與收款、收入展望與業務、結案專案、驗收與交付流程、團隊產出、待辦／資料／系統）逐一呼叫工具 → 每個面向的燈號與一句話、最重要的三件事、現況／要注意／建議 → `save_report(type: advisor)`；顯示在「AI 顧問」頁。不建立 insight／待辦（那是每日簡報的工作） |
| 每週營運回顧 | 週一 09:00 | 上週交付＋財務＋業務＋團隊，跟上週報告比較 → `save_report(type: weekly_company, notify: true)`，並寫入 vault。報告開頭的「一頁重點」（`---` 之前：現金與收款／結案專案／驗收隊列／每人產出／本週三件事）整段轉純文字推到 LINE |
| 月結 | 每月 2 日 09:00 | 月度財務指標、推估準確度（上月推估 vs 實際） |

**執行方式**（`~/Library/LaunchAgents/tw.infolink.daily-brief.plist` 呼叫腳本）：

```bash
#!/bin/bash
# scripts/claude-daily-brief.sh
cd "/Users/kenneth/Library/CloudStorage/OneDrive-聯騰資訊股份有限公司/2ndBrain"
claude -p "執行 infolink MCP 的 daily-brief prompt" \
  --allowedTools "mcp__infolink__*,Read,Glob,Grep" \
  --output-format json \
  >> ~/Library/Logs/infolink/daily-brief.log 2>&1
```

- `--allowedTools` 限定只能用 infolink 工具與讀檔，不能改 vault、不能跑 shell。
- 失敗（app 沒開、未登入）會寫 log；app 端若「預期的 daily_brief 報告到 10:00 還沒出現」會自己發一則 warning。
- Claude Code 的雲端排程（`/schedule`）連不到本機的 `127.0.0.1`，所以不適用；要用 launchd。

### 隨時問

互動時直接問 Claude 即可，例如「現在有什麼要注意的？」「長照款如果延到 11 月，年底現金剩多少？」。Claude 會先 `get_briefing`，必要時 `query_metrics` / `run_readonly_sql`，再回答；如果回答中產生新的風險，按 skill 規則寫回。

---

## 6. 通知

| 通道 | 用途 | 實作 |
| --- | --- | --- |
| Filament 資料庫通知 | 所有 insight 與報告，後台右上角鈴鐺 | Filament 內建 database notifications |
| LINE | `critical` 即時推；每日簡報與週報推摘要＋連結 | **LINE 官方帳號＋Messaging API** push message（LINE Notify 已於 2025/3 停止服務）。需要 Channel access token 與自己的 LINE userId |
| macOS 通知 | 在電腦前時的即時提醒 | 由 launchd 腳本收尾時呼叫 `osascript -e 'display notification ...'`；容器內無法直接發 |

**路由規則**（app 的 `Notifier` 決定，Claude 不用管）：

- `critical` insight：立即 LINE＋資料庫通知；同一個 fingerprint 24 小時內最多推一次。
- `warning`：不即時推，彙整進當天的每日簡報。
- `info`：只進後台。
- 勿擾時段 22:00–08:00：`critical` 以外全部延到早上。
- **每日待辦提醒**：`infolink:notify-todos`，週一～五 08:10（app 排程，不經 Claude）。內容是「今天先做這三件」——自己名下逾期最久／今天到期的前三筆待辦，加一行「另有幾件到期／逾期；已交辦逾期幾件（依負責人）」與待辦清單連結。沒有到期的待辦就不發；`--dry-run` 只印出訊息。
- 每則推播都記在 `notifications_log`。

LINE 官方帳號免費方案每月有則數上限；只推給自己一人、每天 1–3 則，用不完。

---

## 7. REST API（備援）

給不走 MCP 的腳本（例如將來的銀行 CSV 自動下載、Excel 匯出）用。`/api/v1/*`，Sanctum token，與 MCP 工具一對一對應，同樣呼叫 `Domain/` 層：

```
GET  /api/v1/briefing
GET  /api/v1/metrics?keys[]=cash.balance&from=2026-01-01
POST /api/v1/metrics            (批次)
POST /api/v1/bank-transactions  (批次)
POST /api/v1/insights
POST /api/v1/reports
```

另提供 Artisan 指令版（`docker compose exec app php artisan infolink:record-metric cash.balance 1072776 --date=2026-09-22`），方便在 shell 裡手動補資料。
