# 05 實作路線圖

原則：**每個階段結束都要能用**，而且先做「每天會打開」的部分。今年的重點是四案結案收款，所以財務與待辦排在最前面，AI 自動化排後面。

時間估算是以「我用 Claude Code 開發、每天投入 1–2 小時」計算，不佔用工程師的時間。

---

## Phase 0：地基（約 1 天）

- [ ] `docker-compose.yml`：app / scheduler / queue / db（PostgreSQL 17），只綁 `127.0.0.1`
- [ ] `.env` 改用 pgsql；加上 `VAULT_PATH`、`REDMINE_URL`、`REDMINE_API_KEY`
- [ ] 安裝套件（依 AGENTS.md，改依賴前要先確認）：`filament/filament:^5`、`laravel/sanctum`
- [ ] Filament panel 與單一管理者帳號
- [ ] 每日 `pg_dump` 備份排程
- [ ] 建立共通欄位的 trait（`HasSource`：`source`、`external_key`、`actor`、`vault_ref`）

**驗收**：`docker compose up -d` 後能登入 `http://127.0.0.1:8080/admin`；重開機後資料還在；備份檔有產生。

## Phase 1：財務＋待辦 MVP（約 1 週）

- [ ] 資料表：`metric_definitions`、`metric_values`、`bank_accounts`、`bank_transactions`、`customers`、`projects`、`receivables`、`cost_baselines`、`cash_forecasts`、`action_items`、`insights`
- [ ] Domain services：`MetricRecorder`、`TransactionImporter`（含餘額連續性檢查）、`ReceivableService`、`CashForecaster`、`InsightService`（fingerprint 去重）
- [ ] Filament Resources：應收、交易、客戶／專案、待辦、insights
- [ ] 首頁：財務卡片＋「今天要處理」＋現金推估圖
- [ ] **資料灌入**：請 Claude 讀以下筆記，產生 seeder 或直接匯入
  - `03.Business/Finance/帳戶流水分析 2025-07~2026-08.md`（14 個月交易）
  - `03.Business/Finance/帳戶流水 2026-07~08（彰銀中壢）.md`、`帳戶流水 2026-09（彰銀中壢）.md`
  - `03.Business/Finance/2026 下半年現金推估.md`（應收表、月成本、推估）

**驗收**：首頁的現金餘額 = 1,072,776、未收應收（含稅）= 1,365,000、推估年底 ≈ 2,128,778，與筆記一致；手動把一筆應收標成已收後，卡片與推估立即更新。

## Phase 2：Redmine 同步（約 3–4 天）

- [ ] 資料表：`redmine_issues`、`redmine_time_entries`、`redmine_status_snapshots`、`sync_runs`
- [ ] `infolink:sync-redmine`（增量／`--full`）、`infolink:snapshot-redmine`
- [ ] 交付指標計算（驗證中依文豪／他人拆分、停滯、淨流量…）
- [ ] 首次全量匯入，並用 `created_on` / `closed_on` 重建歷史存量曲線
- [ ] Filament：交付總覽 Page、議題鏡像、工時
- [ ] 首頁加「資料新鮮度」列

**驗收**：用 app 算出的 W36 數字跟《2026-W36 Redmine 週報》一致（新增 46、結案 72、驗證中 214 其中文豪 153）；離開公司網路時同步標成 failed，但不發通知。

## Phase 3：Claude 整合（約 1 週）← 核心

- [ ] `InfolinkServer`（laravel/mcp）＋讀取工具：`get_briefing`、`query_metrics`、`list_*`、`redmine_summary`、`get_cash_position`
- [ ] 寫入工具：`record_metrics`、`import_bank_transactions`、`upsert_receivable`、`record_receivable_payment`、`save_cash_forecast`、`raise_insight`、`resolve_insight`、`create/update_action_item`、`save_report`
- [ ] Sanctum token 管理頁（read／write 權限）；每筆寫入記錄 `actor`
- [ ] PostgreSQL 唯讀角色 `infolink_ro`＋`v_*` view＋`run_readonly_sql`
- [ ] `claude mcp add` 註冊（user scope）
- [ ] 新增 `~/.claude/skills/infolink/SKILL.md`（寫回規則、fingerprint 命名）
- [ ] 修改 redmine skill 與 2ndBrain `CLAUDE.md`，加入「結論寫回 app」的規則
- [ ] MCP prompts：`daily-brief`、`weekly-company-review`
- [ ] 資料表：`reports`；Filament 報告頁
- [ ] 每個 MCP 工具的 Pest 測試（特別是冪等：同一呼叫跑兩次，資料筆數不變）

**驗收**：在 vault 裡對 Claude 說「9 月的彰銀明細更新進來」，Claude 能完成匯入、沖銷應收、更新推估、建立逾期 insight；重說一次不會產生重複資料。對 Claude 說「現在有什麼要注意的」，它回答的數字與首頁一致。

## Phase 4：主動通知（約 3 天）

- [ ] `alert_rules`＋`RuleEvaluator`（每次指標寫入後、以及每小時執行）
- [ ] `Notifier`：資料庫通知、LINE Messaging API、勿擾時段、24 小時去重、`notifications_log`
- [ ] 申請 LINE 官方帳號與 Messaging API channel，取得自己的 userId
- [ ] launchd：每日簡報（平日 08:30）、每週回顧（週一 09:00）、月結（每月 2 日）
- [ ] 「簡報未產生」的自我監控規則

**驗收**：把一筆應收的到期日改成 5 天前，1 小時內手機收到 LINE；平日早上 08:35 前後台出現當日簡報，手機收到摘要。

## Phase 5：業務與分析（持續）

- [ ] `deals`、`deal_events`；看板視圖；「沒有下一步」規則
- [ ] 推估準確度追蹤（每月比較上月推估與實際）
- [ ] 每月經常性收入、人事比趨勢
- [ ] 月結報告自動寫入 vault 的 `03.Business/Finance/`（透過 Claude）
- [ ] SaaS POS 上線後：`subscriptions`、MRR／流失

---

## 待決事項

| # | 問題 | 預設做法（沒意見就照這樣做） |
| --- | --- | --- |
| 1 | 資料庫用 PostgreSQL 還是 MySQL？ | PostgreSQL（分析功能較完整，JSONB） |
| 2 | 四個「年底前結案」的專案是哪四個？目標日？ | 先用現金推估裡的應收對象建立：長照、墊腳石（商城 APP、APOS 2.0）、我識，請確認 |
| 3 | 應收一律未稅＋5% 嗎？ | 是（我識已證實）；個別不同的在該筆 `tax_rate` 調整 |
| 4 | vault 待辦要不要改用 Obsidian Tasks 格式（`- [ ] ... 📅 日期`）？ | 是；掃描範圍先限 `03.Business/` 與 `01.Inbox/` |
| 5 | 同事（文豪、鈺文）要不要看這個 app？ | 先不要；只有我自己。要開放時再加角色權限，財務頁面限本人 |
| 6 | 每日簡報時間 | 平日 08:30 |
| 7 | 要不要把 app 產出的報告自動寫回 vault？ | 由 Claude 寫（launchd 腳本允許寫 `03.Business/INFOLINK/報告/`），app 本身不寫 OneDrive |

## 風險

- **資料沒人更新，儀表板就沒用。** 財務資料靠我每月丟明細給 Claude；Redmine 靠自動同步。第一個月每週檢查一次「資料新鮮度」列。
- **指標被誤讀。** 例如「文豪結案數」看起來像產能。對策：每個指標的 `description` 寫清楚定義，`get_briefing` 會附上，Claude 分析時一定會讀到。
- **範圍膨脹。** 這是內部工具，不是產品。Phase 3 完成前不做業務看板、不做手機版、不做多人權限。
