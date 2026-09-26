<?php

use App\Filament\Resources\ActionItems\Pages\ListActionItems;
use App\Filament\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Filament\Resources\CostBaselines\Pages\ListCostBaselines;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Insights\Pages\ListInsights;
use App\Filament\Resources\MetricDefinitions\Pages\ListMetricDefinitions;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Receivables\Pages\ListReceivables;
use App\Models\ActionItem;
use App\Models\BankTransaction;
use App\Models\CostBaseline;
use App\Models\Customer;
use App\Models\Insight;
use App\Models\MetricDefinition;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders the list page with records', function (string $page, string $model, array $states = [[], []]) {
    $records = $model::factory()->count(count($states))->sequence(...$states)->create();

    Livewire::test($page)
        ->assertOk()
        ->assertCanSeeTableRecords($records);
})->with([
    '應收帳款' => [ListReceivables::class, Receivable::class],
    '銀行交易' => [ListBankTransactions::class, BankTransaction::class],
    '月成本基準' => [ListCostBaselines::class, CostBaseline::class, [['effective_from' => '2026-01-01'], ['effective_from' => '2026-07-01']]],
    '客戶' => [ListCustomers::class, Customer::class],
    '專案' => [ListProjects::class, Project::class],
    '待辦' => [ListActionItems::class, ActionItem::class],
    '注意事項' => [ListInsights::class, Insight::class],
    '指標定義' => [ListMetricDefinitions::class, MetricDefinition::class],
]);

it('serves every resource index over http', function (string $uri) {
    Receivable::factory()->create(['expected_on' => today()->subDay()]);
    BankTransaction::factory()->create();
    CostBaseline::factory()->create();
    Project::factory()->create();
    ActionItem::factory()->create();
    Insight::factory()->create();
    MetricDefinition::factory()->create();

    $this->get($uri)->assertOk();
})->with([
    '/admin/receivables',
    '/admin/bank-transactions',
    '/admin/cost-baselines',
    '/admin/customers',
    '/admin/projects',
    '/admin/action-items',
    '/admin/insights',
    '/admin/metric-definitions',
]);
