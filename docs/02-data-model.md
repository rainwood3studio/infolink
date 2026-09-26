# 02 資料模型

## 共通欄位（寫入追溯）

除了純設定表，**每張業務表都有以下欄位**，這是「分析可追溯」與「Claude 可安全重跑」的基礎：

| 欄位 | 型別 | 用途 |
| --- | --- | --- |
| `source` | enum：`manual` / `redmine` / `vault` / `claude` / `bank` / `system` | 資料從哪來 |
| `external_key` | string, nullable | 來源端的唯一鍵（Redmine issue id、交易的雜湊、vault 檔案＋行內容雜湊）。`unique(source, external_key)`，**upsert 用** |
| `actor` | string | 誰寫的：使用者名稱或 token 名稱（`claude-cli`、`launchd-brief`…） |
| `vault_ref` | string, nullable | 相關的 vault 筆記路徑，例如 `03.Business/Finance/2026 下半年現金推估.md` |
| `notes` | text, nullable | 備註 |
| `created_at` / `updated_at` | timestamp | |

金額一律存 **整數（新台幣元）**，並標明未稅或含稅（見財務表），不用浮點數。

---

## 1. 指標（通用、可延伸）

大部分儀表板數字都走這兩張表。新增一個指標只要加一筆定義，不用改 schema。

### `metric_definitions`

| 欄位 | 說明 |
| --- | --- |
| `key` (PK) | 例：`cash.balance`、`delivery.verifying.others` |
| `name` | 顯示名稱：「現金餘額」 |
| `category` | `finance` / `sales` / `delivery` / `company` |
| `unit` | `twd` / `count` / `hours` / `ratio` / `months` / `days` |
| `period_type` | 主要粒度：`day` / `week` / `month` / `snapshot` |
| `better` | `up` / `down` / `none`（決定趨勢箭頭顏色） |
| `target` | 目標值，nullable |
| `warn_threshold` / `critical_threshold` | 門檻，nullable（規則引擎的簡易版） |
| `calculator` | nullable；有值代表由系統計算的類別名稱，無值代表外部寫入 |
| `description` | 指標定義與計算方式（**給 Claude 看的**，避免它誤讀） |
| `is_pinned` | 是否顯示在首頁 |

### `metric_values`

| 欄位 | 說明 |
| --- | --- |
| `metric_key` | FK |
| `period_start` | date；`snapshot` 類型用觀測日 |
| `dimension` | string，預設 `''`；拆維度用，例如 `project:tcsb-5f-b2c`、`assignee:文豪` |
| `value` | decimal(18,4) |
| `source` / `actor` / `vault_ref` / `notes` | 共通欄位 |

`unique(metric_key, period_start, dimension)`：同一期重寫就是更新，不會重複。

---

## 2. 財務

### `bank_accounts`
`name`（彰銀中壢）、`account_no_masked`、`currency`、`is_primary`。

### `bank_transactions`

| 欄位 | 說明 |
| --- | --- |
| `bank_account_id` | FK |
| `txn_date` | date |
| `summary` | 原始摘要（「匯款　我識出版社」） |
| `counterparty` | 解讀後的對象，nullable |
| `withdrawal` / `deposit` | integer |
| `balance` | integer，銀行列印的餘額（匯入時用來驗證連續性） |
| `category` | `revenue` / `salary` / `insurance` / `tax` / `rent` / `subscription` / `reimbursement` / `other` |
| `is_one_off` | bool：一次性支出（中秋獎金），計算常態月成本時排除 |
| `receivable_id` | nullable：這筆入帳沖銷哪一筆應收 |
| `external_key` | `sha1(帳號+日期+摘要+金額+餘額)`，重複匯入同一份明細不會重複 |

### `customers`
`name`、`short_name`（墊腳石）、`redmine_project_ids`（int[]）、`status`、`notes`。

### `projects`（公司層級的「案子」，不等於 Redmine 專案）
`customer_id`、`name`（APOS 2.0）、`contract_amount_untaxed`、`status`（`active` / `closing` / `closed`）、`redmine_project_id`、`target_close_date`。

### `receivables`（應收／收款節點）

| 欄位 | 說明 |
| --- | --- |
| `customer_id` / `project_id` | FK |
| `item` | 「期中款」「尾款」「9 月維運」 |
| `amount_untaxed` | integer |
| `tax_rate` | 預設 0.05 |
| `amount_taxed` | generated：`round(amount_untaxed * (1+tax_rate))` |
| `expected_on` | date |
| `confidence` | `high` / `low` |
| `status` | `planned` / `invoiced` / `received` / `overdue` / `cancelled` |
| `invoiced_on` / `received_on` | date, nullable |
| `is_recurring` | 維運費這類每月固定款 |

`overdue` 不存：由 `expected_on < today and status in (planned, invoiced)` 算出來，避免忘記更新。

### `cost_baselines`
常態月成本的版本紀錄：`effective_from`、`monthly_cost`（目前 229,666）、`breakdown`（JSONB：薪資 161,137、勞健保…）。推估時取最新一版。

### `cash_forecasts`（推估快照）
`as_of`（基準日）、`opening_balance`、`rows`（JSONB：逐月「月份、流入、流出、餘額」）、`assumptions`（JSONB/text）、`min_balance`、`min_balance_month`、`year_end_balance`。
**每次重算存新的一筆**，就能回頭比較「8/30 推估年底 180 萬 vs 9/22 推估 213 萬」。

---

## 3. 業務

### `deals`（業務機會）

| 欄位 | 說明 |
| --- | --- |
| `customer_id` / `prospect_name` | 還不是客戶時用 `prospect_name`（例：多羅滿賞鯨） |
| `title` | 「賞鯨訂位中樞」 |
| `stage` | `lead` / `proposal` / `negotiation` / `won` / `lost` |
| `amount_untaxed` / `probability` | 加權金額 = 兩者相乘 |
| `recurring_monthly` | 若成交後有月費（SaaS／維運） |
| `expected_close_on` | |
| `next_action` / `next_action_on` | 下一步與日期（沒填的會被規則標出來） |
| `vault_ref` | 例：`03.Business/Projects/多羅滿賞鯨/賞鯨訂位中樞 提案書 v3.5.md` |

### `deal_events`
階段變更與互動紀錄：`deal_id`、`occurred_on`、`type`（`meeting` / `proposal_sent` / `stage_change` / `note`）、`content`。

SaaS POS 上線後再加 `subscriptions`（MRR、流失），現在不建。

---

## 4. Redmine 鏡像

### `redmine_issues`
`id`（沿用 Redmine issue id 作 PK）、`project_id`、`project_identifier`、`tracker`、`status`、`is_closed`、`assignee_name`、`author_name`、`subject`、`priority`、`due_date`、`done_ratio`、`estimated_hours`、`created_on`、`updated_on`、`closed_on`、`raw`（JSONB 原始回應）、`synced_at`。

### `redmine_time_entries`
`id`、`issue_id`、`project_identifier`、`user_name`、`activity`、`hours`、`spent_on`、`comments`、`raw`。

### `redmine_status_snapshots`（每日一次）
`snapshot_date`、`project_identifier`、`status`、`assignee_name`、`count`、`stalled_30d`、`stalled_90d`、`overdue`。
Redmine API 沒有「某天各狀態有幾筆」的歷史查詢，**只有自己每天拍照才會有趨勢**，所以這張表從第一天就要開始累積。

---

## 5. 待辦、洞察、報告

### `action_items`（待辦）

| 欄位 | 說明 |
| --- | --- |
| `title` / `detail` | |
| `priority` | `p1` / `p2` / `p3` |
| `status` | `todo` / `doing` / `waiting` / `done` / `dropped` |
| `due_on` | nullable |
| `owner` | 預設自己；可填同事名稱 |
| `related_type` / `related_id` | polymorphic：連到 receivable、deal、redmine_issue、insight… |
| `source` | `manual` / `vault`（掃描）/ `claude`（分析產生） |

### `insights`（注意事項／風險／洞察）

跟待辦的差別：**insight 是「需要知道的事」，action item 是「需要做的事」**。一個 insight 可以衍生多個 action item。

| 欄位 | 說明 |
| --- | --- |
| `kind` | `risk` / `anomaly` / `reminder` / `opportunity` / `observation` |
| `severity` | `critical` / `warning` / `info` |
| `category` | `finance` / `sales` / `delivery` / `company` |
| `title` | 一句話：「長照期中款 47.25 萬逾期 3 天」 |
| `body` | Markdown：依據、數字、建議動作 |
| `evidence` | JSONB：引用的指標值、議題編號、交易，方便點回去查 |
| `fingerprint` | 去重鍵，例如 `receivable-overdue:42`。**同一個 fingerprint 還沒解決時不會重複建立**，只更新內容與 `last_seen_at` |
| `status` | `open` / `acknowledged` / `resolved` / `dismissed` |
| `first_seen_at` / `last_seen_at` / `resolved_at` | 可以算「這個問題拖了多久」 |
| `notified_at` | 已推播時間（避免重複通知） |
| `expires_at` | 過期自動收掉（例如「本週」類提醒） |

### `alert_rules`（確定性規則）
`name`、`metric_key` 或 `query_class`、`operator`、`threshold`、`severity`、`title_template`、`fingerprint_template`、`is_active`、`notify_channels`（JSONB）。詳細規則見 04 文件。

### `reports`
`type`（`daily_brief` / `weekly_redmine` / `weekly_company` / `monthly_finance` / `adhoc`）、`period_start`、`period_end`、`title`、`body`（Markdown）、`metrics_snapshot`（JSONB：產生報告當下引用的指標值）、`vault_ref`、`source`、`actor`。

### `sync_runs`
`job`（`redmine_issues` / `redmine_time` / `vault_tasks` / `snapshot` / `rules`）、`started_at`、`finished_at`、`status`（`ok` / `failed` / `skipped`）、`stats`（JSONB：新增／更新筆數）、`error`。儀表板會顯示「資料新鮮度」。

### `notifications_log`
`channel`、`insight_id`／`report_id`、`payload`、`sent_at`、`status`、`error`。

---

## 分析用 view（給 Claude 與圖表）

放在 PostgreSQL，`infolink_ro` 角色只能讀這些：

| view | 內容 |
| --- | --- |
| `v_metric_latest` | 每個指標最新值、前一期值、變化量、是否超門檻 |
| `v_receivables_open` | 未收應收＋逾期天數＋含稅金額 |
| `v_cash_monthly` | 按月彙總流入／流出（排除一次性）／期末餘額 |
| `v_delivery_trend` | 由 snapshots 算出的每日存量、驗證中（文豪／他人）、停滯數 |
| `v_open_attention` | 首頁「今天要處理」：open 的 critical/warning insight＋逾期或今天到期的 action item |
