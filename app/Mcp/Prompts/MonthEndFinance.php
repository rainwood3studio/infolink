<?php

namespace App\Mcp\Prompts;

use Carbon\CarbonImmutable;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('month-end-finance')]
#[Description('月結（每月 2 日 09:00）：月度財務指標、推估準確度（上月推估 vs 實際）、新一版現金推估 → save_report(monthly_finance, notify: true)。')]
class MonthEndFinance extends InfolinkPrompt
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ], [
            'month.date_format' => 'month must be YYYY-MM.',
        ]);

        $month = filled($validated['month'] ?? null)
            ? CarbonImmutable::createFromFormat('!Y-m', $validated['month'])
            : today()->toImmutable()->subMonthNoOverflow()->startOfMonth();

        $label = $month->format('Y-m');
        $start = $month->toDateString();
        $end = $month->endOfMonth()->toDateString();
        $previousMonth = $month->subMonthNoOverflow()->format('Y-m');

        return Response::text(<<<MARKDOWN
        # 聯騰資訊月結：{$label}

        你是聯騰資訊（3 人軟體公司）的財務助理。結算 {$label}（{$start} ～ {$end}），檢查推估準確度，存新一版推估，並把結論寫回 app。

        ## 步驟

        1. `get_briefing` 與 `get_cash_position`：現況、資料新鮮度。**確認 {$label} 的銀行明細已匯入到月底**（最新交易日期 ≥ {$end} 或接近）；沒有的話，報告開頭註明並請使用者提供明細（匯入用 `import_bank_transactions`，依對帳單順序、整數金額、民國年轉西元）。
        2. **當月實際**：`run_readonly_sql` 查 `v_cash_monthly`（{$label} 的流入、流出（排除一次性）、期末餘額），`query_metrics`（`from: {$start}`、`to: {$end}`）查 `cash.*`、`revenue.*`、`ar.*`。
        3. **收款**：`list_receivables` —— {$label} 應收但未收的（逾期）、已收的是否都已連到入帳交易。發現入帳未對應應收 → `record_receivable_payment`（用 `transaction_date` + `transaction_deposit` 對應；一筆入帳可能涵蓋多筆應收）。
        4. **推估準確度**：找 {$previousMonth} 月底前後存的推估（`list_reports` `type: monthly_finance` 的上一份 `metrics_snapshot`，或 `get_cash_position.previous_snapshot`），比較它對 {$label} 月底餘額的預測 vs 實際餘額；逐項說明差異（哪筆款延後、哪筆一次性支出沒預期到）。
        5. **新一版推估**：`save_cash_forecast`（預設以最新交易日為基準；需要情境時加 `label`，例如「長照延到11月」）。若最低點低於安全水位 → `raise_insight`（`cash-low:<YYYY-MM>`）。
        6. **寫回指標**：app 沒有自動計算的月度指標 → `record_metrics`（`period_start: {$start}`），例如 `revenue.received`、`company.net_cashflow_ytd`；有 calculator 的不要寫。
        7. 風險 → `raise_insight`（例如 `receivable-overdue:<客戶>-<項目>`）；已收到的逾期款 → `resolve_insight`；要動手的 → `create_action_item`。
        8. `save_report`：`type: monthly_finance`、`period_start: {$start}`、`notify: true`。`body`：一句話總結 → 當月收支與期末餘額 → 收款狀況 → 推估準確度 → 新推估（最低點、年底餘額、與上一版差異）→ 要做的事。`metrics_snapshot` 放引用的數字。

        {$this->writeBackRules()}

        {$this->domainCaveats()}
        MARKDOWN);
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('month', '要結算的月份 YYYY-MM，預設上個月。'),
        ];
    }
}
