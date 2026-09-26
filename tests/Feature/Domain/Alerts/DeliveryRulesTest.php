<?php

use App\Domain\Alerts\RuleEvaluator;
use App\Domain\Metrics\MetricRecorder;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Enums\ProjectStatus;
use App\Models\Insight;
use App\Models\Project;
use App\Models\RedmineIssue;
use Carbon\CarbonImmutable;
use Database\Seeders\AlertRuleSeeder;
use Database\Seeders\MetricDefinitionSeeder;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00'));
    $this->seed([MetricDefinitionSeeder::class, AlertRuleSeeder::class]);
    $this->evaluate = fn (string $key) => app(RuleEvaluator::class)->evaluate($key);
    $this->record = fn (string $key, float $value, string $date, string $dimension = '') => app(MetricRecorder::class)
        ->record($key, $value, CarbonImmutable::parse($date), $dimension);
});

describe('delivery-backlog-growing', function () {
    test('fires when net flow was positive for the last 3 completed weeks', function () {
        foreach (['2026-08-31' => 5, '2026-09-07' => 3, '2026-09-14' => 1] as $week => $net) {
            ($this->record)('delivery.net_flow', $net, $week);
        }

        ($this->evaluate)('delivery-backlog-growing');

        expect(Insight::sole())
            ->fingerprint->toBe('delivery-backlog-growing')
            ->severity->toBe(InsightSeverity::Warning)
            ->title->toBe('連續 3 週新增議題多於結案（未結 +9）');
    });

    test('does not fire when one week was flat, the current partial week is needed, or last week is missing', function (array $weeks) {
        foreach ($weeks as $week => $net) {
            ($this->record)('delivery.net_flow', $net, $week);
        }

        ($this->evaluate)('delivery-backlog-growing');

        expect(Insight::count())->toBe(0);
    })->with([
        'flat week' => [['2026-08-31' => 5, '2026-09-07' => 0, '2026-09-14' => 1]],
        'current week counted' => [['2026-08-31' => -2, '2026-09-07' => 3, '2026-09-14' => 1, '2026-09-21' => 4]],
        'stale data' => [['2026-08-24' => 5, '2026-08-31' => 3, '2026-09-07' => 1]],
        'gap' => [['2026-08-24' => 5, '2026-09-07' => 3, '2026-09-14' => 1]],
    ]);
});

describe('delivery-offflow', function () {
    test('fires per project above 20 or on a week-over-week increase above 10', function () {
        RedmineIssue::factory()->create(['project_identifier' => 'tcsb-5f-b2c', 'project_name' => '墊腳石 | 5F | B2C']);

        foreach (['tcsb-5f-b2c' => 15, 'apos' => 5, 'edge' => 10, 'gone' => 30] as $project => $value) {
            ($this->record)('delivery.verifying.others', $value, '2026-09-17', "project:{$project}");
        }

        foreach (['tcsb-5f-b2c' => 21, 'apos' => 16, 'edge' => 20, 'fresh' => 20] as $project => $value) {
            ($this->record)('delivery.verifying.others', $value, '2026-09-24', "project:{$project}");
        }

        ($this->evaluate)('delivery-offflow');

        expect(Insight::query()->orderBy('fingerprint')->pluck('title', 'fingerprint')->all())->toBe([
            'delivery-offflow:apos' => 'apos：16 筆驗證中未走驗收流程（7 天 +11）',
            'delivery-offflow:tcsb-5f-b2c' => '墊腳石 | 5F | B2C：21 筆驗證中未走驗收流程（7 天 +6）',
        ]);
    });

    test('resolves a project once it is back under the thresholds', function () {
        ($this->record)('delivery.verifying.others', 25, '2026-09-24', 'project:apos');
        ($this->evaluate)('delivery-offflow');
        expect(Insight::sole()->title)->toBe('apos：25 筆驗證中未走驗收流程');

        ($this->record)('delivery.verifying.others', 12, '2026-09-25', 'project:apos');
        ($this->evaluate)('delivery-offflow');

        expect(Insight::sole()->status)->toBe(InsightStatus::Resolved);
    });
});

describe('delivery-stalled', function () {
    test('fires only on a week-over-week increase above 10 per project', function () {
        foreach (['a' => 50, 'b' => 50, 'c' => 200] as $project => $value) {
            ($this->record)('delivery.stalled_90d', $value, '2026-09-17', "project:{$project}");
        }

        foreach (['a' => 61, 'b' => 60, 'c' => 200] as $project => $value) {
            ($this->record)('delivery.stalled_90d', $value, '2026-09-24', "project:{$project}");
        }

        ($this->evaluate)('delivery-stalled');

        expect(Insight::sole())
            ->fingerprint->toBe('delivery-stalled:a')
            ->title->toBe('a：停滯 90 天的議題增為 61 筆（7 天 +11）');
    });
});

describe('closing-risk', function () {
    beforeEach(function () {
        $this->closing = fn (array $attributes = []) => Project::factory()->create([
            'name' => '墊腳石商城 APP',
            'status' => ProjectStatus::Closing,
            'redmine_project_id' => 15,
            'target_close_date' => today()->addDays(29),
            ...$attributes,
        ]);
        $this->openIssues = fn (int $count) => RedmineIssue::factory()->count($count)->create(['project_id' => 15]);
    });

    test('fires critical when the target is within 30 days and more than 10 issues are open', function () {
        ($this->closing)();
        ($this->openIssues)(11);
        RedmineIssue::factory()->count(5)->create(['project_id' => 15, 'is_closed' => true, 'status' => '已結案']);

        ($this->evaluate)('closing-risk');

        expect(Insight::sole())
            ->fingerprint->toBe('closing-risk:tcsb-5f-b2c')
            ->severity->toBe(InsightSeverity::Critical)
            ->title->toBe('墊腳石商城 APP：距目標結案日 29 天，仍有 11 筆未結議題');
    });

    test('a past target date still fires', function () {
        ($this->closing)(['target_close_date' => today()->subDays(5)]);
        ($this->openIssues)(11);

        ($this->evaluate)('closing-risk');

        expect(Insight::sole()->title)->toBe('墊腳石商城 APP：已超過目標結案日 5 天，仍有 11 筆未結議題');
    });

    test('does not fire at the boundaries', function (array $attributes, int $open) {
        ($this->closing)($attributes);
        ($this->openIssues)($open);

        ($this->evaluate)('closing-risk');

        expect(Insight::count())->toBe(0);
    })->with([
        '10 open' => [[], 10],
        '30 days left' => [['target_close_date' => '2026-10-24'], 11],
        'not closing' => [['status' => ProjectStatus::Active], 11],
        'no redmine link' => [['redmine_project_id' => null], 11],
    ]);

    test('lists closing projects without a target date in one info insight, resolved once filled in', function () {
        $project = ($this->closing)(['target_close_date' => null]);
        ($this->closing)(['name' => '長照', 'target_close_date' => null, 'redmine_project_id' => null]);
        ($this->openIssues)(20);

        ($this->evaluate)('closing-risk');

        expect(Insight::sole())
            ->fingerprint->toBe('closing-target-missing')
            ->severity->toBe(InsightSeverity::Info)
            ->title->toBe('2 個結案中專案未設定目標結案日：墊腳石商城 APP、長照');

        Project::query()->update(['target_close_date' => today()->addMonths(3)]);
        ($this->evaluate)('closing-risk');

        expect(Insight::sole()->status)->toBe(InsightStatus::Resolved);
    });
});
