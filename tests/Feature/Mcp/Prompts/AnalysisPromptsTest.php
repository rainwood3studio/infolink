<?php

use App\Mcp\Prompts\CompanyAdvisor;
use App\Mcp\Prompts\DailyBrief;
use App\Mcp\Prompts\DevActivityReview;
use App\Mcp\Prompts\MonthEndFinance;
use App\Mcp\Prompts\WeeklyCompanyReview;
use App\Mcp\Servers\InfolinkServer;

beforeEach(function () {
    $this->travelTo('2026-09-28 08:30:00');
});

test('the analysis prompts are listed by name', function () {
    InfolinkServer::actingAs(mcpUser(['read', 'write']))
        ->prompts()
        ->assertRegistered([DailyBrief::class, WeeklyCompanyReview::class, MonthEndFinance::class]);

    expect([(new DailyBrief)->name(), (new WeeklyCompanyReview)->name(), (new MonthEndFinance)->name()])
        ->toBe(['daily-brief', 'weekly-company-review', 'month-end-finance']);
});

test('every prompt carries the write-back rules and domain caveats', function (string $prompt) {
    InfolinkServer::actingAs(mcpUser())
        ->prompt($prompt)
        ->assertOk()
        ->assertSee([
            'get_briefing',
            'raise_insight',
            'create_action_item',
            'save_report',
            '<類別>-<對象>:<識別>',
            'receivable-overdue:長照-期中款',
            '文豪',
            'is_recurring',
            '未稅',
            'confidence: low',
            '不要刪資料',
        ]);
})->with([DailyBrief::class, WeeklyCompanyReview::class, MonthEndFinance::class]);

test('every prompt tells Claude to reuse action items and to stop adding when they pile up', function (string $prompt) {
    InfolinkServer::actingAs(mcpUser())
        ->prompt($prompt)
        ->assertOk()
        ->assertSee([
            '同一件事已經有待辦',
            '`update_action_item` 改它',
            '未完成超過 10 筆，或逾期（`days_overdue` > 0）超過 5 筆時，**不要再 `create_action_item`**',
            '待辦的 `owner` 是人名',
            '`dev_activity_summary` 的 `developers`',
        ]);
})->with([DailyBrief::class, WeeklyCompanyReview::class, MonthEndFinance::class]);

test('daily-brief takes stock of pending action items before creating any', function () {
    InfolinkServer::actingAs(mcpUser())
        ->prompt(DailyBrief::class)
        ->assertSee([
            '先盤點待辦再動手',
            '未完成超過 10 筆或逾期超過 5 筆 → 今天**不新增待辦**',
            '待辦已堆積 N 筆（逾期 M 筆），今天不新增',
            '建議放棄（dropped）與建議交辦（給誰）',
            '還沒有對應待辦的 → `create_action_item`',
        ]);
});

test('weekly-company-review names every action item overdue by more than a week with a recommendation', function () {
    InfolinkServer::actingAs(mcpUser())
        ->prompt(WeeklyCompanyReview::class)
        ->assertSee([
            '逾期超過 7 天',
            '`days_overdue` > 7',
            '逐筆點名',
            '**做**（本週，寫哪一天）／**延**（新日期）／**交辦**（給誰）／**放棄**',
        ]);
});

test('daily-brief defaults to today and accepts a date', function () {
    InfolinkServer::actingAs(mcpUser())
        ->prompt(DailyBrief::class)
        ->assertSee(['每日簡報：2026-09-28', 'type: daily_brief', 'period_start: 2026-09-28', 'notify: true']);

    InfolinkServer::actingAs(mcpUser())
        ->prompt(DailyBrief::class, ['date' => '2026-09-25'])
        ->assertSee('period_start: 2026-09-25');
});

test('weekly-company-review defaults to last week and accepts a date or ISO week', function () {
    InfolinkServer::actingAs(mcpUser())
        ->prompt(WeeklyCompanyReview::class)
        ->assertSee(['2026-09-21 ～ 2026-09-27', 'type: weekly_company', 'period_start: 2026-09-21', 'period_start: 2026-09-14', 'redmine_summary']);

    InfolinkServer::actingAs(mcpUser())
        ->prompt(WeeklyCompanyReview::class, ['week' => '2026-W39'])
        ->assertSee('2026-09-21 ～ 2026-09-27');

    InfolinkServer::actingAs(mcpUser())
        ->prompt(WeeklyCompanyReview::class, ['week' => '2026-09-10'])
        ->assertSee('2026-09-07 ～ 2026-09-13');
});

test('month-end-finance defaults to last month', function () {
    InfolinkServer::actingAs(mcpUser())
        ->prompt(MonthEndFinance::class)
        ->assertSee(['月結：2026-08', 'type: monthly_finance', 'period_start: 2026-08-01', 'save_cash_forecast', 'record_receivable_payment', '推估準確度']);

    InfolinkServer::actingAs(mcpUser())
        ->prompt(MonthEndFinance::class, ['month' => '2026-09'])
        ->assertSee(['2026-09-01 ～ 2026-09-30', 'v_cash_monthly']);
});

test('prompts reject malformed arguments', function (string $prompt, array $arguments, string $message) {
    InfolinkServer::actingAs(mcpUser())
        ->prompt($prompt, $arguments)
        ->assertHasErrors([$message]);
})->with([
    'date' => [DailyBrief::class, ['date' => '9/28'], 'date must be YYYY-MM-DD.'],
    'week' => [WeeklyCompanyReview::class, ['week' => 'last'], 'week must be a date inside the week'],
    'month' => [MonthEndFinance::class, ['month' => '2026-9'], 'month must be YYYY-MM.'],
]);

test('dev-activity-review focuses on the previous workday and saves a dev_review report', function () {
    InfolinkServer::actingAs(mcpUser())
        ->prompt(DevActivityReview::class)
        ->assertOk()
        ->assertSee([
            '開發活動分析：2026-09-28',
            'dev_activity_summary',
            '`from: 2026-09-21`、`to: 2026-09-27`',
            '重點日是 **2026-09-25**',
            'type: dev_review',
            'period_start: 2026-09-28',
            'notify: false',
            '行數不能比較人',
            'dev-untracked:<人名>',
        ]);

    InfolinkServer::actingAs(mcpUser())
        ->prompt(DevActivityReview::class, ['date' => '2026-10-01'])
        ->assertSee(['重點日是 **2026-09-30**', 'period_start: 2026-10-01']);
});

test('company-advisor walks every area and saves an advisor report without writing insights', function () {
    InfolinkServer::actingAs(mcpUser())
        ->prompt(CompanyAdvisor::class)
        ->assertOk()
        ->assertSee([
            'AI 顧問分析：2026-09-28',
            'get_cash_position',
            'revenue_outlook',
            'closing_projects',
            'acceptance_queue',
            'dev_activity_summary',
            'list_action_items',
            'type: advisor',
            'period_start: 2026-09-28',
            'notify: false',
            '`cash`（現金與收款）、`revenue`（收入展望與業務）、`closing`（結案專案）、`acceptance`（驗收與交付流程）、`team`（團隊產出）、`operations`（待辦、資料與系統）',
            '**不要** `raise_insight`',
            '文豪',
        ]);
});
