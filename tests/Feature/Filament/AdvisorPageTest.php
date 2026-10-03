<?php

use App\Enums\ReportType;
use App\Filament\Pages\Advisor;
use App\Models\Report;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-10-05 11:00:00');
    $this->actingAs(User::factory()->create());
});

it('explains itself before the first analysis exists', function () {
    $this->get(Advisor::getUrl())->assertOk()->assertSee('還沒有 AI 顧問分析');
});

it('shows the traffic lights, the top three and the full analysis of the newest report', function () {
    Report::factory()->create(['type' => ReportType::Advisor, 'period_start' => '2026-10-02', 'title' => '舊的', 'body' => '舊的分析']);
    Report::factory()->create([
        'type' => ReportType::Advisor,
        'period_start' => '2026-10-05',
        'title' => 'AI 顧問分析 2026-10-05',
        'body' => "尾款卡在驗收。\n\n## 最重要的三件事\n\n1. 先驗收 <script>alert(1)</script>",
        'metrics_snapshot' => [
            'advisor' => [
                'domains' => [
                    ['key' => 'cash', 'status' => 'red', 'headline' => '47 萬期中款逾期，發票還沒開'],
                    ['key' => 'team', 'status' => 'green', 'headline' => '三人都有穩定產出'],
                    ['key' => 'closing', 'status' => 'purple', 'headline' => '兩案未結數不減反增'],
                ],
                'top' => ['開長照期中款發票', '把 51 張流程外議題轉給驗收者'],
            ],
            'cash.balance' => 1072776,
        ],
    ]);
    Report::factory()->create(['type' => ReportType::DailyBrief, 'period_start' => '2026-10-05', 'body' => '今天先開發票']);
    Report::factory()->create(['type' => ReportType::DevReview, 'period_start' => '2026-10-05', 'title' => '開發活動分析 2026-10-05']);

    Livewire::test(Advisor::class)
        ->assertSeeInOrder(['現金與收款', '本週要處理', '47 萬期中款逾期', '收入展望與業務', '沒有評估', '結案專案', '沒有評估', '兩案未結數不減反增', '團隊產出', '正常', '三人都有穩定產出'])
        ->assertSeeInOrder(['最重要的三件事', '開長照期中款發票', '把 51 張流程外議題轉給驗收者', '完整分析', '尾款卡在驗收'])
        ->assertSeeInOrder(['每日簡報 · 10/05', '今天先開發票', '其他分析', '開發活動分析 2026-10-05'])
        ->assertDontSee('舊的分析')
        ->assertDontSee('不是最新的')
        ->assertDontSeeHtml('<script>alert(1)</script>');
});

it('flags an analysis that is older than expected', function (string $now, string $reportDate, bool $stale) {
    $this->travelTo($now);
    Report::factory()->create(['type' => ReportType::Advisor, 'period_start' => $reportDate]);

    $page = Livewire::test(Advisor::class);

    $stale ? $page->assertSee('不是最新的') : $page->assertDontSee('不是最新的');
})->with([
    'weekday afternoon, today missing' => ['2026-10-05 11:00:00', '2026-10-02', true],
    'weekday early morning, last weekday present' => ['2026-10-05 08:00:00', '2026-10-02', false],
    'weekend, friday present' => ['2026-10-04 15:00:00', '2026-10-02', false],
    'weekend, friday missing' => ['2026-10-04 15:00:00', '2026-10-01', true],
]);
