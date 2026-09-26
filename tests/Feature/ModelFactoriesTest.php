<?php

use App\Enums\ReceivableStatus;
use App\Models\ActionItem;
use App\Models\BankTransaction;
use App\Models\CashForecast;
use App\Models\CostBaseline;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\Insight;
use App\Models\MetricValue;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusChange;
use App\Models\RedmineStatusSnapshot;
use App\Models\RedmineTimeEntry;
use App\Models\SyncRun;

test('every factory persists a valid record', function (string $model) {
    expect($model::factory()->create()->exists)->toBeTrue();
})->with([
    MetricValue::class,
    BankTransaction::class,
    Project::class,
    Receivable::class,
    CostBaseline::class,
    CashForecast::class,
    ActionItem::class,
    Insight::class,
    Deal::class,
    DealEvent::class,
]);

test('the taxed amount is derived from the untaxed amount and tax rate', function () {
    $receivable = Receivable::factory()->create(['amount_untaxed' => 450_000, 'tax_rate' => 0.05]);

    expect($receivable->fresh()->amount_taxed)->toBe(472_500);
});

test('an outstanding receivable past its expected date is overdue', function () {
    Receivable::factory()->create(['expected_on' => today()->subDay()]);
    Receivable::factory()->create(['expected_on' => today()->subDay(), 'status' => ReceivableStatus::Received]);
    Receivable::factory()->create(['expected_on' => today()]);

    expect(Receivable::overdue()->count())->toBe(1)
        ->and(Receivable::overdue()->sole()->is_overdue)->toBeTrue();
});

test('every redmine factory persists a valid record', function (string $model) {
    expect($model::factory()->create()->exists)->toBeTrue();
})->with([
    RedmineIssue::class,
    RedmineTimeEntry::class,
    RedmineStatusSnapshot::class,
    RedmineStatusChange::class,
    SyncRun::class,
]);

test('the acceptor is matched by the start of the display name', function () {
    config(['services.redmine.acceptor_name' => '文豪']);

    expect(RedmineIssue::isAcceptor('文豪 王'))->toBeTrue()
        ->and(RedmineIssue::isAcceptor('裕樺 李'))->toBeFalse()
        ->and(RedmineIssue::isAcceptor(null))->toBeFalse();
});

test('a deal is weighted by its probability and named by customer or prospect', function () {
    $deal = Deal::factory()->create(['amount_untaxed' => 1_000_000, 'probability' => 30, 'prospect_name' => '多羅滿賞鯨']);

    expect($deal->weighted_amount)->toBe(300_000)
        ->and($deal->party_name)->toBe('多羅滿賞鯨');
});
