<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('daily-brief')]
#[Description('每日簡報（週一～五 08:30）：get_briefing → 找出今天要注意的 3–5 件事 → raise_insight / create_action_item → save_report(daily_brief, notify: true)。')]
class DailyBrief extends InfolinkPrompt
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'date.date_format' => 'date must be YYYY-MM-DD.',
        ]);

        $date = $this->parseDate($validated['date'] ?? null, today()->toImmutable())->toDateString();

        return Response::text(<<<MARKDOWN
        # 聯騰資訊每日簡報：{$date}

        你是聯騰資訊（3 人軟體公司）的營運助理。用 infolink MCP 的工具產出 {$date} 的每日簡報，並把結論寫回 app。只用 infolink 工具與讀檔，不要改 vault、不要跑 shell。

        ## 步驟

        1. `get_briefing`：一次拿到全貌（釘選指標與變化、超門檻指標、open insights、逾期／近期待辦、未收應收、最新現金推估、資料新鮮度）。資料過期就在報告開頭註明。
        2. 只在需要細節時補查：`get_cash_position`、`list_receivables`（`overdue_only: true`）、`redmine_summary`、`closing_projects`（結案中專案：卡在誰、照近期速度何時結得完、掛著多少應收）、`acceptance_queue`（驗收隊列：多大、多久清得完、哪些擋著結案專案）、`revenue_outlook`（每月缺口、現金何時開始下滑；沒有金額或預計成交日的業務機會要點名）、`query_metrics`、`list_insights`、`list_action_items`。有結案中專案時一定要看 `closing_projects`，引用它的推估結案日，不要自己估。
        3. 挑出**今天要注意的 3–5 件事**，依急迫度排序：逾期或本週到期的收款、現金低點、交付停滯／驗證堆積、到期的待辦、沒有下一步的業務機會。沒事就說沒事，不要湊數。
        4. **先盤點待辦再動手**：`list_action_items`（未完成的全部）。數一下未完成幾筆、逾期（`days_overdue` > 0）幾筆。
           - 未完成超過 10 筆或逾期超過 5 筆 → 今天**不新增待辦**；報告第一行寫「待辦已堆積 N 筆（逾期 M 筆），今天不新增」，接著列出建議放棄（dropped）與建議交辦（給誰）的待辦。
           - 今天要講的事如果已經有待辦 → `update_action_item`（改期、補說明），不要再建一筆相近的。
        5. 每件事照寫回規則處理：
           - 新的或持續中的風險 → `raise_insight`（同一件事沿用同一個 fingerprint）。
           - 已經解決的 → `resolve_insight`，附原因。
           - 今天需要有人動手、而且還沒有對應待辦的 → `create_action_item`（`insight_id` 連回，給 `due_on`；該由同事做的填 `owner`，名字用 `dev_activity_summary` 的 `developers`）。
        6. `save_report`：`type: daily_brief`、`period_start: {$date}`、`notify: true`；`body` 為 Markdown（開頭一句話總結，再列 3–5 件事，每件附數字與建議動作），`metrics_snapshot` 放引用的指標值。
        7. 最後在對話中回覆簡短摘要（同報告開頭）與寫入了哪些 insight／待辦。

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
            new Argument('date', '簡報日期 YYYY-MM-DD，預設今天。'),
        ];
    }
}
