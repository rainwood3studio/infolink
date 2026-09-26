<?php

namespace App\Mcp\Prompts;

use Carbon\CarbonImmutable;
use Laravel\Mcp\Server\Prompt;

/**
 * Base for the scheduled-analysis prompts (docs/03-integration.md §5). Holds the write-back rules and domain
 * caveats every analysis must follow, so they are edited in one place.
 */
abstract class InfolinkPrompt extends Prompt
{
    /**
     * Rules shared by every analysis prompt (docs/03-integration.md §2).
     */
    protected function writeBackRules(): string
    {
        return <<<'MARKDOWN'
        ## 寫回規則（必須遵守）

        - 分析產生的**數字** → `record_metrics`（或對應的業務工具），不要只寫在報告文字裡。有 calculator 的指標由 app 計算，不要自己寫。
        - 發現的**風險／異常** → 先 `list_insights`（含 `fingerprint` 篩選）確認沒有同一件事，再 `raise_insight`。同一件事一律用同一個 fingerprint，app 會更新而不是新增。
        - **fingerprint 命名**：`<類別>-<對象>:<識別>`，前綴小寫英數，例如
          `receivable-overdue:長照-期中款`、`receivable-due:我識-尾款`、`cash-low:2026-11`、`delivery-stalled:tcsb-5f-b2c`、`delivery-offflow:tcsb-5f-b2c`、`sales-no-next-action:多羅滿賞鯨`。
        - 問題已經不存在（款項已入帳、議題已處理）→ `resolve_insight`，附上原因 `note`。
        - 需要有人**動手做的事** → 先 `list_action_items` 查重，再 `create_action_item` 並用 `insight_id` 連回 insight；做完的用 `update_action_item`（status: done）。
        - 完整的**分析文字** → `save_report`（Markdown，`metrics_snapshot` 放引用的指標值）。
        - **不要刪資料**：只能 resolve、dismiss 或把待辦設為 dropped。
        - 所有寫入都冪等，重跑同一份分析不會產生重複資料；不確定時寧可重跑，不要換新的 key 或 fingerprint。
        MARKDOWN;
    }

    /**
     * Domain caveats that change how numbers must be read.
     */
    protected function domainCaveats(): string
    {
        return <<<'MARKDOWN'
        ## 解讀注意事項

        - **驗收瓶頸**：所有最終驗收都由文豪一個人做。Redmine 的「已關閉」數量反映的是文豪的驗收速度，**不是**團隊產出（throughput）。看交付要看驗證中堆積（`delivery.verifying.wenhao` vs `delivery.verifying.others`）與停滯；驗證中掛在非文豪名下＝流程外（off-flow），要點名。
        - **常態收入（維運費）**：每月固定的維運費是 `is_recurring` 的應收，每月一筆。它們不算在應收卡片的高／低信心與逾期合計裡（另列 recurring），但會進現金推估。不要把維運費當成案子的尾款，也不要重複計算。
        - **未稅 vs 含稅**：應收的 `amount_untaxed` 是**未稅**；現金、推估、應收合計（`*_taxed`）是**含稅**（一般 5%，部分維運費報價免稅 tax_rate 0）。比較入帳金額時用含稅金額；報告裡寫金額要註明未稅或含稅。
        - **不要猜金額**：金額或日期不確定時，用 `confidence: low` 並在 `notes` 說明依據；低信心應收不會進現金推估。沒有依據的數字寧可不寫。
        - 金額一律是整數新台幣元。先讀指標的 `description`（`list_metric_definitions`）再解讀數字。
        - 資料新鮮度：`get_briefing` 會回報各同步最後成功時間；資料過期時先在報告開頭註明，不要把舊資料當成今天的狀況。
        MARKDOWN;
    }

    /**
     * Parse an optional date-like argument, falling back to the given default.
     */
    protected function parseDate(?string $value, CarbonImmutable $default): CarbonImmutable
    {
        return filled($value) ? CarbonImmutable::parse($value)->startOfDay() : $default;
    }
}
