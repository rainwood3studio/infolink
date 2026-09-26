<?php

namespace App\Domain\Alerts\Rules;

use App\Domain\Alerts\Firing;
use App\Enums\InsightKind;
use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Models\AlertRule;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * 資料過期: a sync job that has failed since its last successful run AND has had no successful run for at least
 * `threshold` hours (default 24). Without a successful run ever, the clock starts at the first failure.
 * Single failures (e.g. a night off the VPN) therefore do not fire until they last a whole day. Skipped runs are
 * ignored. params `jobs` (list or comma-separated SyncJob values) limits the jobs checked; default all.
 *
 * Template variables: job, job_label, hours, failures, last_ok.
 */
class SyncFailedRule extends BaseRule
{
    public static function label(): string
    {
        return '同步連續失敗';
    }

    public function evaluate(AlertRule $rule, CarbonImmutable $now): array
    {
        $hours = $this->threshold($rule, 24);
        $jobsParam = $this->param($rule, 'jobs', []);
        $jobs = collect(is_string($jobsParam) ? explode(',', $jobsParam) : (array) $jobsParam)
            ->map(fn (mixed $job): ?SyncJob => SyncJob::tryFrom(trim((string) $job)))
            ->filter()
            ->whenEmpty(fn () => collect(SyncJob::cases()));
        $firings = [];

        foreach ($jobs as $job) {
            $lastOk = SyncRun::latestFor($job, SyncStatus::Ok);
            $failures = SyncRun::query()
                ->where('job', $job)
                ->where('status', SyncStatus::Failed)
                ->when($lastOk !== null, fn ($query) => $query->where('started_at', '>', $lastOk->started_at))
                ->orderBy('started_at')
                ->get();

            if ($failures->isEmpty()) {
                continue;
            }

            $since = CarbonImmutable::instance($lastOk !== null ? ($lastOk->finished_at ?? $lastOk->started_at) : $failures->first()->started_at);

            if ($since->gt($now->subSeconds((int) round($hours * 3600)))) {
                continue;
            }

            $elapsed = (int) floor($since->diffInHours($now));
            $latestError = $failures->last()->error;

            $firings[] = new Firing(
                vars: [
                    'job' => $job->value,
                    'job_label' => $job->getLabel(),
                    'hours' => $elapsed,
                    'failures' => $failures->count(),
                    'last_ok' => $lastOk !== null ? $since->format('Y-m-d H:i') : '從未成功',
                ],
                body: implode("\n", [
                    "- 工作：{$job->getLabel()}（`{$job->value}`）",
                    '- 上次成功：'.($lastOk !== null ? $since->format('Y-m-d H:i') : '從未成功')."，已 **{$elapsed} 小時**",
                    sprintf('- 之後失敗 %d 次，最近一次 %s', $failures->count(), $failures->last()->started_at->format('Y-m-d H:i')),
                    '- 最近錯誤：'.(filled($latestError) ? Str::limit(str_replace("\n", ' ', $latestError), 300) : '（無訊息）'),
                    '',
                    '**建議**：確認 VPN 與 Redmine 連線、查看「同步紀錄」的錯誤訊息，修好後手動執行一次同步；在那之前相關指標與儀表板數字是舊的。',
                ]),
                evidence: [
                    'job' => $job->value,
                    'last_ok_at' => $lastOk !== null ? $since->toIso8601String() : null,
                    'failures' => $failures->count(),
                    'latest_failed_run_id' => $failures->last()->id,
                ],
                kind: InsightKind::Anomaly,
            );
        }

        return $firings;
    }
}
