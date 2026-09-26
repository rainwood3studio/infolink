<?php

use App\Domain\Alerts\RuleEvaluator;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Enums\ReportType;
use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Models\Insight;
use App\Models\Report;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;
use Database\Seeders\AlertRuleSeeder;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00'));
    $this->seed(AlertRuleSeeder::class);
    $this->evaluate = fn (string $key) => app(RuleEvaluator::class)->evaluate($key);
    $this->syncRun = fn (SyncStatus $status, int $hoursAgo, SyncJob $job = SyncJob::RedmineIssues) => SyncRun::factory()->create([
        'job' => $job,
        'status' => $status,
        'started_at' => now()->subHours($hoursAgo)->subMinute(),
        'finished_at' => now()->subHours($hoursAgo),
        'error' => $status === SyncStatus::Failed ? 'cURL error 28: Connection timed out' : null,
    ]);
});

describe('sync-failed', function () {
    test('fires when a job failed and has not succeeded for 24 hours', function () {
        ($this->syncRun)(SyncStatus::Ok, 24);
        ($this->syncRun)(SyncStatus::Failed, 10);
        ($this->syncRun)(SyncStatus::Failed, 1);

        ($this->evaluate)('sync-failed');

        $insight = Insight::sole();

        expect($insight)
            ->fingerprint->toBe('sync-failed:redmine_issues')
            ->severity->toBe(InsightSeverity::Warning)
            ->title->toBe('Redmine 議題 已 24 小時沒有成功同步（失敗 2 次）')
            ->and($insight->body)->toContain('cURL error 28');
    });

    test('does not fire within 24 hours of the last success, or without failures since', function (array $runs) {
        foreach ($runs as [$status, $hoursAgo]) {
            ($this->syncRun)($status, $hoursAgo);
        }

        ($this->evaluate)('sync-failed');

        expect(Insight::count())->toBe(0);
    })->with([
        'overnight off VPN' => [[[SyncStatus::Ok, 23], [SyncStatus::Failed, 12], [SyncStatus::Failed, 1]]],
        'no failure since' => [[[SyncStatus::Failed, 50], [SyncStatus::Ok, 30]]],
        'never ok, failing 5h' => [[[SyncStatus::Failed, 5]]],
    ]);

    test('without any success the clock starts at the first failure', function () {
        ($this->syncRun)(SyncStatus::Failed, 25, SyncJob::RedmineTime);

        ($this->evaluate)('sync-failed');

        expect(Insight::sole()->fingerprint)->toBe('sync-failed:redmine_time');
    });

    test('resolves after the next successful run', function () {
        ($this->syncRun)(SyncStatus::Ok, 30);
        ($this->syncRun)(SyncStatus::Failed, 2);
        ($this->evaluate)('sync-failed');

        ($this->syncRun)(SyncStatus::Ok, 0);
        ($this->evaluate)('sync-failed');

        expect(Insight::sole()->status)->toBe(InsightStatus::Resolved);
    });
});

describe('brief-missing', function () {
    test('fires on a weekday from 10:00 without today\'s daily brief', function (string $now, bool $fires) {
        $this->travelTo(CarbonImmutable::parse($now));

        ($this->evaluate)('brief-missing');

        expect(Insight::count())->toBe($fires ? 1 : 0);
    })->with([
        'Thu 09:59' => ['2026-09-24 09:59', false],
        'Thu 10:00' => ['2026-09-24 10:00', true],
        'Sat 11:00' => ['2026-09-26 11:00', false],
    ]);

    test('the insight is keyed by date and resolves once the brief exists', function () {
        $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00'));
        Report::factory()->create(['type' => ReportType::DailyBrief, 'period_start' => '2026-09-23']);
        Report::factory()->create(['type' => ReportType::WeeklyRedmine, 'period_start' => '2026-09-24']);

        ($this->evaluate)('brief-missing');

        expect(Insight::sole())
            ->fingerprint->toBe('brief-missing:2026-09-24')
            ->title->toBe('2026-09-24 每日簡報到 10:00 仍未產生');

        Report::factory()->create(['type' => ReportType::DailyBrief, 'period_start' => '2026-09-24']);
        ($this->evaluate)('brief-missing');

        expect(Insight::sole()->status)->toBe(InsightStatus::Resolved);
    });
});
