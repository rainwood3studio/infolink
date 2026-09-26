<?php

use App\Domain\Alerts\RuleEvaluator;
use App\Enums\InsightSeverity;
use App\Models\AlertRule;
use Database\Seeders\AlertRuleSeeder;

test('it seeds every rule from the doc, all active', function () {
    $this->seed(AlertRuleSeeder::class);

    expect(AlertRule::query()->orderBy('key')->pluck('key')->all())->toBe([
        'brief-missing', 'cash-low', 'cash-runway', 'closing-risk', 'deal-stale', 'delivery-backlog-growing',
        'delivery-offflow', 'delivery-stalled', 'receivable-due', 'receivable-overdue', 'sync-failed', 'vat-reserve',
    ])
        ->and(AlertRule::query()->where('is_active', false)->pluck('key')->all())->toBe([]);
});

test('every seeded rule resolves to a rule implementation', function () {
    $this->seed(AlertRuleSeeder::class);
    $evaluator = app(RuleEvaluator::class);

    AlertRule::all()->each(fn (AlertRule $rule) => expect($evaluator->handlerFor($rule))->not->toBeNull());
});

test('re-seeding does not overwrite edited rules or duplicate them', function () {
    $this->seed(AlertRuleSeeder::class);
    AlertRule::query()->where('key', 'cash-runway')->update(['threshold' => 4, 'severity' => InsightSeverity::Critical, 'is_active' => false, 'title_template' => '改過']);
    AlertRule::query()->where('key', 'deal-stale')->update(['is_active' => false]);

    $this->seed(AlertRuleSeeder::class);

    expect(AlertRule::count())->toBe(count(AlertRuleSeeder::rules()))
        ->and(AlertRule::where('key', 'cash-runway')->sole())
        ->threshold->toEqual('4.0000')
        ->severity->toBe(InsightSeverity::Critical)
        ->is_active->toBeFalse()
        ->title_template->toBe('改過')
        ->and(AlertRule::where('key', 'deal-stale')->sole()->is_active)->toBeFalse();
});

test('a deleted rule is re-created on the next seed', function () {
    $this->seed(AlertRuleSeeder::class);
    AlertRule::where('key', 'vat-reserve')->delete();

    $this->seed(AlertRuleSeeder::class);

    expect(AlertRule::where('key', 'vat-reserve')->exists())->toBeTrue();
});
