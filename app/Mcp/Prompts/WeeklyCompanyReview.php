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
#[Description('每週營運回顧（週一 09:00）：上週交付＋財務＋業務＋團隊，跟上週報告比較 → save_report(weekly_company, notify: true)；報告開頭的「一頁重點」整段推到 LINE，並寫入 vault。')]
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
        6. **團隊**：`dev_activity_summary`（`from: {$start}`、`to: {$end}`）——每人這一週做了什麼、送驗／驗收數，跟前一週比；`project_pnl`（`days: 7`）——這一週人力花在哪個客戶與專案、有沒有投入在尚未簽約的工作。行數與 commit 數不拿來比較人。
        7. **待辦清理**：`list_action_items`（未完成的全部）。**逾期超過 7 天**（`days_overdue` > 7）的待辦要**逐筆點名**（標題、負責人、逾期天數），每筆給一個建議，四選一：**做**（本週，寫哪一天）／**延**（新日期）／**交辦**（給誰）／**放棄**。理由一句話。不要自己改這些待辦的狀態，由 Kenneth 決定；沒有逾期超過 7 天的就寫「沒有逾期超過 7 天的待辦」。
        8. **寫回**：
           - 本週數字中 app 沒有自動計算的 → `record_metrics`（`period_start: {$start}`）。
           - 風險 → `raise_insight`；已解決的 → `resolve_insight`；要動手的 → `create_action_item`（先照寫回規則查重、看待辦是否已堆積）。
        9. `save_report`：`type: weekly_company`、`period_start: {$start}`、`period_end: {$end}`、`notify: true`。`body` 分兩段，中間用單獨一行 `---` 隔開（見下方「報告格式」）。`metrics_snapshot` 放引用的指標值；`vault_ref` 填 vault 筆記路徑。
        10. 照原本習慣在 vault 寫一份週報筆記（若只允許讀檔，就在回覆中附上完整 Markdown 與建議路徑），兩邊互相連結。

        ## 報告格式

        `---` 之前是**一頁重點**：app 會把這一段原文（轉成純文字）整段推到 Kenneth 的 LINE，他在手機上只看得到這段，所以要自成一體、看完就知道公司這週怎麼樣。規則：

        - 只用標題與條列，**不要表格、不要連結、不要程式碼**；每一點一行、30 字內，帶數字。全段 600 字內。
        - 第一行：一句話總結（40 字內）。
        - 接著固定五節，標題照抄：
          - `## 現金與收款`：餘額與資料日期、本週入帳、逾期與下週到期的應收。
          - `## 結案專案`：每個結案中專案一行——未結數（跟上週比）、目標日、照目前速度結不結得完。
          - `## 驗收隊列`：隊列大小（跟上週比）、本週驗收 vs 送驗、流程外幾張。
          - `## 每人產出`：每人一行——做了什麼（白話）、送驗或驗收幾張。
          - `## 本週三件事`：這一週最該做的三件事，寫到「誰、做什麼、哪一天前」。

        `---` 之後是**詳細**（只存在 app 與 vault，不推播）：交付 → 財務 → 業務與收入展望 → 團隊與人力投入 → 與上週相比的變化 → 逾期待辦（做／延／交辦／放棄）→ 下週要做的事。這裡可以用表格。

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
