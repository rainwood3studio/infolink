<?php

use App\Enums\DealStage;
use App\Enums\ProjectStatus;
use App\Enums\ReceivableStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\ClosingProjectsWidget;
use App\Filament\Widgets\SalesStatsWidget;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RedmineIssue;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the sales cards show the weighted pipeline, open count and deals without a next action', function () {
    Deal::factory()->create(['amount_untaxed' => 1_000_000, 'probability' => 50]);
    Deal::factory()->create(['amount_untaxed' => 400_000, 'probability' => 25, 'next_action_on' => null]);
    Deal::factory()->create(['stage' => DealStage::Won, 'amount_untaxed' => 9_000_000]);

    Livewire::test(SalesStatsWidget::class)
        ->assertSee('加權業務機會')
        ->assertSee('60.0 萬')
        ->assertSee('進行中機會數')
        ->assertSee('合計 140.0 萬')
        ->assertSee('沒有下一步的機會')
        ->assertSee('需要排下一步')
        ->assertSee('/admin/deals/board');
});

test('closing projects show countdown, open issues and outstanding receivables', function () {
    $customer = Customer::factory()->create(['short_name' => '長照']);
    $linked = Project::factory()->create([
        'customer_id' => $customer->id,
        'name' => '長照平台二期',
        'status' => ProjectStatus::Closing,
        'target_close_date' => today()->addDays(20),
        'redmine_project_id' => 42,
    ]);
    RedmineIssue::factory()->count(3)->create(['project_id' => 42, 'is_closed' => false]);
    RedmineIssue::factory()->create(['project_id' => 42, 'is_closed' => true]);
    Receivable::factory()->create(['project_id' => $linked->id, 'customer_id' => $customer->id, 'amount_untaxed' => 100_000, 'tax_rate' => 0.05]);
    Receivable::factory()->create(['project_id' => $linked->id, 'customer_id' => $customer->id, 'amount_untaxed' => 900_000, 'status' => ReceivableStatus::Received]);

    $unlinked = Project::factory()->create(['name' => '我識官網', 'status' => ProjectStatus::Closing, 'target_close_date' => null, 'redmine_project_id' => null]);
    $active = Project::factory()->create(['name' => '進行中專案', 'status' => ProjectStatus::Active]);

    Livewire::test(ClosingProjectsWidget::class)
        ->assertCanSeeTableRecords([$linked, $unlinked], inOrder: true)
        ->assertCanNotSeeTableRecords([$active])
        ->assertSee('長照')
        ->assertSee('剩 20 天')
        ->assertSee('未設定目標日')
        ->assertSee('未連結 Redmine')
        ->assertTableColumnStateSet('open_issues_count', '3', $linked)
        ->assertSee('NT$105,000');
});

test('the dashboard renders with the sales and closing widgets', function () {
    Deal::factory()->create();
    Project::factory()->create(['status' => ProjectStatus::Closing]);

    $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertSeeLivewire(SalesStatsWidget::class)
        ->assertSeeLivewire(ClosingProjectsWidget::class);
});
