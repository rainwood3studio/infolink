# INFOLINK 營運中樞（infolink app）規劃

> 版本：v0.1（2026-09-26）｜狀態：規劃中，尚未實作
> 專案位置：`/Users/kenneth/Desktop/infolink`（Laravel 13 + Filament 5，本機 Docker）

## 一句話

一個跑在本機 Docker 的**公司營運資料庫＋儀表板**：把 2ndBrain 的分析結果、Redmine 狀態、財務與業務數字集中存進資料庫，讓我打開一頁就知道「公司現在怎麼樣、今天該處理什麼」，並讓 Claude（CLI／2ndBrain agent／Redmine skill）可以**直接讀寫**這個資料庫，隨時分析、產生報告與推播通知。

## 要解決的問題

| 現況 | 問題 | 這個 app 怎麼解 |
| --- | --- | --- |
| 財務推估、帳戶流水寫在 vault 的 Markdown 表格 | 數字只能「看」，不能追蹤趨勢、不能自動提醒 | 結構化存進 DB（交易、應收、推估快照），可查詢、可畫趨勢 |
| Redmine 週報靠每週手動叫 Claude 產生 | 只有當週切片，沒有歷史；指標要重算 | 每小時自動同步＋每日快照，指標天天有值 |
| 待辦與注意事項散在各筆記與腦中 | 容易漏；沒有「今天要處理」的單一清單 | `action_items` + `insights` 兩張表，儀表板首頁集中顯示 |
| Claude 的分析結果只存在對話或一篇筆記裡 | 下次分析無法接續、無法比較 | Claude 透過 MCP 把數字、洞察、報告寫進 DB，下次直接讀 |
| 沒有人會主動提醒 | 收款延遲、現金低點、驗收卡關都是事後才發現 | 規則引擎＋每日 AI 簡報，透過 LINE／macOS 通知推給我 |

## 文件索引

| 文件 | 內容 |
| --- | --- |
| [01-architecture.md](01-architecture.md) | 系統架構、Docker 組成、資料流、各資料的「真相來源」 |
| [02-data-model.md](02-data-model.md) | 資料表設計（指標、財務、業務、Redmine 鏡像、待辦、洞察、報告） |
| [03-integration.md](03-integration.md) | **Claude 整合的核心**：MCP 工具、REST API、Redmine 同步、vault 連接、排程分析、通知 |
| [04-metrics-and-dashboard.md](04-metrics-and-dashboard.md) | 指標目錄、警示規則、Filament 頁面與儀表板版面 |
| [05-roadmap.md](05-roadmap.md) | 分階段實作計畫、每階段驗收標準、待決事項 |

## 關鍵決策摘要

1. **Claude 的主要溝通介面是 MCP**（`laravel/mcp`，已隨 Boost 安裝 v1.0.1）。Claude CLI、vault 裡的 agent、Redmine skill 都用同一組 MCP 工具讀寫，不需要各自寫 HTTP 呼叫。REST API 只是給腳本／外部用的備援。
2. **所有寫入都走同一個 Domain Service 層**，並以 `source` + `external_key` 做冪等 upsert。人工（Filament）、Claude、同步排程寫進來的資料格式完全一致，重跑不會重複。
3. **Redmine 由 app 自己定時拉**（Laravel HTTP client 打 REST API），不依賴 Claude 在線；Redmine skill 負責的是「分析」與「寫回洞察／報告」。
4. **vault 以唯讀方式掛進容器**。app 不直接改 OneDrive 裡的檔案，避免跟 OneDrive 同步衝突；要寫回 vault 的內容（例如週報）由 Claude 在 vault 端寫。
5. **確定性規則在 Laravel、判斷與敘事交給 Claude**：門檻警示（現金低於 X、收款逾期）由排程即時算、不花 token；「這週哪裡有風險、為什麼」由每日／每週 Claude 簡報產生。
6. **資料庫用 PostgreSQL**（取代目前的 SQLite）：分析需要 window function、JSONB、`date_trunc`；另開唯讀角色給 Claude 做 ad-hoc 查詢。
7. **只綁 `127.0.0.1`、單一使用者**：財務資料不對外，API／MCP 一律要 token。
