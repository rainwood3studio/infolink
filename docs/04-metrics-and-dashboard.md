# 04 指標、警示規則與儀表板

指標以「2026 年讓公司開始獲利」為主軸挑選：**現金撐不撐得住 → 錢收不收得回來 → 案子結不結得了 → 下一筆生意在哪裡**。初始值取自 vault 現有筆記，方便上線時驗證計算是否正確。

## 指標目錄（初版）

`★` 表示釘選在首頁。來源：`calc` = app 自動計算、`sync` = 同步產生、`claude` / `manual` = 外部寫入。

### 財務（finance）

| key | 名稱 | 粒度 | 來源 | 計算方式／說明 | 參考值 |
| --- | --- | --- | --- | --- | --- |
| ★ `cash.balance` | 現金餘額 | snapshot | calc | 最新一筆交易的 `balance` | 1,072,776（09/22） |
| ★ `cash.runway_months` | 現金可撐月數 | snapshot | calc | 餘額 ÷ 常態月成本（不計未收款） | 4.7 個月 |
| `cash.monthly_cost` | 常態月成本 | month | calc | 當月支出扣除 `is_one_off` 與代墊 | 229,666 |
| ★ `cash.forecast_min_90d` | 未來 90 天推估最低餘額 | snapshot | calc | 最新 `cash_forecasts` 的 90 天內最低點（只計高確定性應收） | |
| `cash.forecast_year_end` | 推估年底餘額 | snapshot | calc | | 2,128,778 |
| ★ `ar.outstanding_taxed` | 未收應收（含稅） | snapshot | calc | `receivables` 未收加總 | 1,365,000（高確定性） |
| ★ `ar.overdue_taxed` | 逾期應收（含稅） | snapshot | calc | 已過 `expected_on` 仍未收 | |
| `ar.low_confidence_taxed` | 低確定性應收 | snapshot | calc | | 577,500 |
| `revenue.received` | 當月入帳收入 | month | calc | `category = revenue` 的存入加總（含稅） | |
| `revenue.recurring_monthly` | 每月經常性收入 | month | calc | `is_recurring` 應收的月額（墊腳石維運、我識 APP 維護） | 約 12 萬 |
| `cost.personnel_ratio` | 人事占支出比 | month | calc | 薪資＋勞健保＋勞退 ÷ 常態支出 | 85% |
| `tax.vat_reserve` | 營業稅應預留 | snapshot | calc | 本期已開發票銷項稅額 − 已攤入月成本的部分 | 11 月約 8 萬 |
| `company.net_cashflow_ytd` | 今年累計淨現金流 | month | calc | 年度目標「開始獲利」的代理指標 | |

### 業務（sales）

| key | 名稱 | 粒度 | 來源 | 說明 |
| --- | --- | --- | --- | --- |
| ★ `sales.pipeline_weighted` | 加權業務機會金額 | snapshot | calc | Σ(金額 × 成交機率)，不含 won／lost |
| `sales.deals_open` | 進行中業務機會數 | snapshot | calc | 依 stage 拆 dimension |
| `sales.deals_no_next_action` | 沒有下一步的機會 | snapshot | calc | `next_action_on` 為空或已過期 |
| `sales.won_amount` | 當月成交金額 | month | calc | |
| `saas.mrr` | SaaS 月經常性收入 | month | manual | SaaS POS 上線後啟用 |

### 交付（delivery，來自 Redmine）

| key | 名稱 | 粒度 | 來源 | 說明 | 參考值（W36） |
| --- | --- | --- | --- | --- | --- |
| ★ `delivery.open` | 未結案存量 | day | sync | 可拆 `project:` | 601 |
| `delivery.created` | 新增議題 | week | sync | | 46 |
| `delivery.closed` | 結案議題 | week | sync | **不是產能指標**：幾乎全由文豪驗收結案 | 72 |
| ★ `delivery.net_flow` | 淨流量 | week | sync | 新增 − 結案；正數代表 backlog 在長 | −26 |
| ★ `delivery.verifying.wenhao` | 驗證中（文豪隊列） | day | sync | 正常排隊，受單人吞吐限制 | 153 |
| ★ `delivery.verifying.others` | 驗證中（非文豪） | day | sync | 脫離驗收流程，通常已停滯 | 58 |
| `delivery.advanced_to_verify` | 推進到驗證中的筆數 | week | sync | **真正的產能指標**，依 assignee 拆 | |
| `delivery.wenhao_throughput` | 文豪每週驗收量 | week | sync | 公司結案速度的上限 | |
| `delivery.stalled_30d` / `stalled_90d` | 停滯議題 | day | sync | 未結案且超過 30／90 天沒更新 | 90 天：53 |
| `delivery.overdue` | 逾期議題 | day | sync | 有 due_date 且已過期 | |
| `delivery.unassigned` | 未指派 | day | sync | | |
| `delivery.hours_logged` | 登錄工時 | week | sync | 依人拆；目前驗收工時未登錄，**僅供參考** | 25.5 |
| `delivery.inflow_to_wenhao_ratio` | 新案落到文豪的比例 | week | sync | 單點瓶頸指標 | 80% |

### 公司／結案進度（company）

| key | 名稱 | 說明 |
| --- | --- | --- |
| ★ `company.closing_projects` | 年底前待結案專案進度 | 四個結案專案（依 `projects.status = closing`）各自的未結議題數與距目標日天數，dimension 為專案 |

## 警示規則（alert_rules 初始值）

| 規則 | 條件 | 嚴重度 | fingerprint |
| --- | --- | --- | --- |
| 現金低點 | `cash.forecast_min_90d` < 500,000 | critical | `cash-low:<月份>` |
| 現金可撐月數 | `cash.runway_months` < 3 | warning；< 2 為 critical | `cash-runway` |
| 應收即將到期 | 高確定性應收 7 天內到期且未開發票 | info | `receivable-due:<id>` |
| 應收逾期 | 超過 `expected_on` 3 天未收 | warning；超過 30 天為 critical | `receivable-overdue:<id>` |
| 營業稅預留 | 申報月（奇數月 15 日前）的前 14 天，餘額扣掉預留後 < 月成本 | warning | `vat-reserve:<期別>` |
| backlog 成長 | `delivery.net_flow` 連續 3 週 > 0 | warning | `delivery-backlog-growing` |
| 脫離驗收流程 | `delivery.verifying.others` > 20 或週增 > 10 | warning | `delivery-offflow:<project>` |
| 停滯惡化 | `delivery.stalled_90d` 週增 > 10 | warning | `delivery-stalled:<project>` |
| 結案專案風險 | closing 專案距目標日 < 30 天且未結議題 > 10 | critical | `closing-risk:<project>` |
| 業務斷層 | `sales.deals_no_next_action` > 0 持續 7 天 | info | `deal-stale:<id>` |
| 資料過期 | 任一同步連續 24 小時失敗 | warning | `sync-failed:<job>` |
| 每日簡報未產生 | 工作日 10:00 仍無當日 `daily_brief` | warning | `brief-missing:<date>` |

規則只負責「超過門檻」這種能用一行條件判斷的事；「為什麼」「該怎麼辦」交給 Claude 在簡報裡補上。

## Filament 後台

### 導覽結構

```
📊 總覽（Dashboard）
📌 今天要處理          ← insights + action items 合併的工作清單
💰 財務
   ├─ 現金與推估       ← 自訂 Page：餘額趨勢、推估圖、推估版本比較
   ├─ 應收帳款         ← Resource
   ├─ 銀行交易         ← Resource（含 CSV 匯入 Action）
   └─ 月成本基準       ← Resource
🤝 業務
   ├─ 業務機會         ← Resource（表格＋看板）
   └─ 客戶／專案       ← Resource
🛠 交付（Redmine）
   ├─ 交付總覽         ← 自訂 Page：存量趨勢、驗證中拆分、各專案排行
   ├─ 議題（鏡像）      ← 唯讀 Resource，連結回 Redmine
   └─ 工時             ← 唯讀 Resource
📝 報告               ← 唯讀，Markdown 渲染，可依類型／期間篩選
⚙️ 設定
   ├─ 指標定義（可手動輸入數值）
   ├─ 警示規則
   ├─ API Tokens
   └─ 同步紀錄（sync_runs）
```

### 首頁版面

```
┌──────────────────────────────────────────────────────────────────────────┐
│ 資料新鮮度：Redmine 12 分鐘前 ✓ │ 銀行明細 09/22 │ 今日簡報 08:31 ✓       │
├──────────┬──────────┬──────────┬──────────┬──────────┬──────────────────┤
│ 現金餘額  │ 可撐月數  │ 90天最低 │ 未收應收  │ 逾期應收  │ 加權業務機會      │
│ 107.3萬  │ 4.7      │ 107.3萬  │ 136.5萬  │ 47.3萬 ⚠ │ —                │
├──────────┴──────────┴──────────┴──────────┴──────────┴──────────────────┤
│ 📌 今天要處理（critical → warning → 逾期待辦 → 今天到期）                   │
│  🔴 長照期中款 47.25 萬已逾期 3 天            [確認] [建立待辦] [已處理]    │
│  🟠 墊腳石 5F B2C：58 筆驗證中未走驗收流程                                  │
│  ☐ 寄出我識尾款發票（10/15 到期）                                          │
├─────────────────────────────────────┬────────────────────────────────────┤
│ 現金推估（未來 6 個月，折線）          │ 交付：存量＋驗證中（文豪／他人）趨勢  │
├─────────────────────────────────────┼────────────────────────────────────┤
│ 應收時程（依月份堆疊，高／低確定性）   │ 結案專案進度（四案，距目標日）        │
├─────────────────────────────────────┴────────────────────────────────────┤
│ 最新每日簡報（Markdown 摘要，點開看全文）                                    │
└──────────────────────────────────────────────────────────────────────────┘
```

- 每個數字卡片顯示與上一期的變化，顏色依 `better` 決定（現金下降是紅、逾期下降是綠）。
- 點卡片進入該指標的歷史圖與原始紀錄（誰在什麼時候、從哪個來源寫入的）。
- 「今天要處理」的按鈕直接改 insight 狀態，或一鍵從 insight 建立 action item。

### 手動輸入

- 每個 Resource 都可以新增／修改（Redmine 鏡像除外）。
- 「指標定義」頁可以對任一個非計算型指標**手動補值**（例如 SaaS 試用客戶數）。
- 銀行交易支援上傳 CSV；PDF 明細請 Claude 解析後用 `import_bank_transactions` 匯入。
- 所有手動輸入的資料 `source = manual`，在歷史紀錄中可以跟自動來源區分。
