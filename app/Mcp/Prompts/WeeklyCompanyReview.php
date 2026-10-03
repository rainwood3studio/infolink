<?php

namespace App\Mcp\Prompts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('weekly-company-review')]
#[Description('每週營運回顧（週一 09:00）：上週 Redmine 交付＋財務＋業務，跟上週報告比較 → save_report(weekly_company, notify: true)，並寫入 vault。')]
class WeeklyCompanyReview extends InfolinkPrompt
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'week' => ['nullable', 'string', 'regex:/^(\d{4}-\d{2}-\d{2}|\d{4}-W\d{2})$/'],
        ], [
            'week.regex' => 'week must be a date inside the week (YYYY-MM-DD) or an ISO week (YYYY-Www, e.g. 2026-W39).',
        ]);

        $monday = $this->resolveWeek($validated['week'] ?? null);
        $start = $monday->toDateString();
        $end = $monday->addDays(6)->toDateString();
        $previous = $monday->subWeek()->toDateString();

        return Response::text(<<<MARKDOWN
        # 聯騰資訊每週營運回顧：{$start} ～ {$end}

        你是聯騰資訊（3 人軟體公司）的營運助理。回顧 {$start}（週一）到 {$end} 這一週的交付、財務與業務，跟前一週比較，並把結論寫回 app。

        ## 步驟

        1. `get_briefing`：取得現況與資料新鮮度。
        2. **前一週基準**：`get_report`（`type: weekly_company`、`period_start: {$previous}`）。沒有就註明「無上週報告可比較」。
        3. **交付**：`get_report`（`type: weekly_redmine`、`period_start: {$start}`）若已存在就引用；否則 `redmine_summary`，加上 `closing_projects`（各結案專案的未結分佈、推估結案日 vs 目標日）與 `acceptance_queue`（驗收隊列、每週驗收 vs 送驗、清空週數），再用 `query_metrics`（`from: {$start}`、`to: {$end}`）看 `delivery.*` 趨勢。重點：驗證中堆積（文豪 vs 他人）、停滯、逾期、未指派、流入 vs 流出。記住已關閉數＝文豪的驗收量，不是產出。
        4. **財務**：`get_cash_position`（餘額、跑道、推估與上一版差異）、`list_receivables`（本週該收未收、逾期、下週到期）。
        5. **業務**：`list_deals`（若可用）——階段變化、沒有下一步的機會；`revenue_outlook`——每月缺口、現金高點與開始下滑的月份、尾款延後兩個月（`delay_months: 2`）的情況、沒被計入的業務機會缺什麼。
        6. **寫回**：
           - 本週數字中 app 沒有自動計算的 → `record_metrics`（`period_start: {$start}`）。
           - 風險 → `raise_insight`；已解決的 → `resolve_insight`；要動手的 → `create_action_item`。
        7. `save_report`：`type: weekly_company`、`period_start: {$start}`、`period_end: {$end}`、`notify: true`。`body` 結構：一句話總結 → 交付 → 財務 → 業務 → 與上週相比的變化 → 下週要做的事。`metrics_snapshot` 放引用的指標值；`vault_ref` 填 vault 筆記路徑。
        8. 照原本習慣在 vault 寫一份週報筆記（若只允許讀檔，就在回覆中附上完整 Markdown 與建議路徑），兩邊互相連結。

        {$this->writeBackRules()}

        {$this->domainCaveats()}
        MARKDOWN);
    }

    /**
     * The Monday of the requested week; defaults to last week (the review runs on Monday morning).
     */
    protected function resolveWeek(?string $week): CarbonImmutable
    {
        if (blank($week)) {
            return today()->toImmutable()->subWeek()->startOfWeek(CarbonInterface::MONDAY);
        }

        if (preg_match('/^(\d{4})-W(\d{2})$/', $week, $matches) === 1) {
            return CarbonImmutable::now()->setISODate((int) $matches[1], (int) $matches[2])->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        }

        return CarbonImmutable::parse($week)->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('week', '要回顧的週：週內任一天 YYYY-MM-DD 或 ISO 週 YYYY-Www，預設上週。'),
        ];
    }
}
