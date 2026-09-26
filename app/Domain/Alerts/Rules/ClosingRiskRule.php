<?php

namespace App\Domain\Alerts\Rules;

use App\Domain\Alerts\Firing;
use App\Enums\InsightKind;
use App\Enums\InsightSeverity;
use App\Enums\ProjectStatus;
use App\Models\AlertRule;
use App\Models\Project;
use App\Models\RedmineIssue;
use Carbon\CarbonImmutable;

/**
 * 結案專案風險: a `closing` project whose `target_close_date` is within params `days` (default 30; past targets
 * included) and whose linked Redmine project (`redmine_project_id`, issues of that project only, not subprojects)
 * still has more than `threshold` (default 10) open issues.
 *
 * Projects without a target date or Redmine link cannot be judged and are skipped; instead ONE info insight
 * `closing-target-missing` lists the closing projects lacking a target date, since that gap hides the risk.
 *
 * Template variables: project (Redmine identifier), project_name, open, days_left, days_text, target_date.
 */
class ClosingRiskRule extends BaseRule
{
    public const string TARGET_MISSING_FINGERPRINT = 'closing-target-missing';

    public static function label(): string
    {
        return '結案專案風險';
    }

    public function ownedFingerprints(AlertRule $rule): array
    {
        return [...parent::ownedFingerprints($rule), self::TARGET_MISSING_FINGERPRINT];
    }

    public function evaluate(AlertRule $rule, CarbonImmutable $now): array
    {
        $today = $now->startOfDay();
        $threshold = $this->threshold($rule, 10);
        $days = (int) $this->param($rule, 'days', 30);
        $projects = Project::query()->with('customer')->where('status', ProjectStatus::Closing)->orderBy('name')->get();
        $firings = [];

        foreach ($projects as $project) {
            if ($project->target_close_date === null || $project->redmine_project_id === null) {
                continue;
            }

            $daysLeft = (int) $today->diffInDays($project->target_close_date, false);

            if ($daysLeft >= $days) {
                continue;
            }

            $open = RedmineIssue::query()->open()->where('project_id', $project->redmine_project_id)->count();

            if ($open <= $threshold) {
                continue;
            }

            $identifier = RedmineIssue::withTrashed()->where('project_id', $project->redmine_project_id)->value('project_identifier')
                ?? 'redmine-'.$project->redmine_project_id;
            $daysText = $daysLeft >= 0 ? "距目標結案日 {$daysLeft} 天" : '已超過目標結案日 '.abs($daysLeft).' 天';

            $firings[] = new Firing(
                vars: [
                    'project' => $identifier,
                    'project_name' => $project->name,
                    'open' => $open,
                    'days_left' => $daysLeft,
                    'days_text' => $daysText,
                    'target_date' => $project->target_close_date->toDateString(),
                ],
                body: implode("\n", [
                    "- 專案：{$project->name}（Redmine `{$identifier}`）",
                    sprintf('- 目標結案日：%s（%s）', $project->target_close_date->toDateString(), $daysText),
                    sprintf('- 未結議題：**%d 筆**（門檻 %s）', $open, self::number($threshold)),
                    '',
                    '**建議**：列出阻擋結案的議題與負責人，請文豪優先驗收此案；若目標日不可行，和客戶重新確認結案範圍與日期，並同步更新目標日與尾款預計收款日。',
                ]),
                evidence: [
                    'project_id' => $project->id,
                    'redmine_project_id' => $project->redmine_project_id,
                    'redmine_identifier' => $identifier,
                    'target_close_date' => $project->target_close_date->toDateString(),
                    'days_left' => $daysLeft,
                    'open_issues' => $open,
                ],
            );
        }

        $missingTarget = $projects->whereNull('target_close_date')->values();

        if ($missingTarget->isNotEmpty()) {
            $names = $missingTarget->pluck('name');
            $unlinked = $projects->whereNull('redmine_project_id')->pluck('name');

            $firings[] = new Firing(
                vars: [],
                body: implode("\n", [
                    '以下結案中專案沒有目標結案日，無法判斷結案風險：',
                    '',
                    ...$names->map(fn (string $name): string => "- {$name}")->all(),
                    ...($unlinked->isNotEmpty() ? ['', '另外未連結 Redmine 專案：'.$unlinked->implode('、')] : []),
                    '',
                    '**建議**：在「專案」頁補上目標結案日（及 Redmine 專案），結案風險規則才會開始檢查。',
                ]),
                evidence: [
                    'project_ids' => $missingTarget->pluck('id')->all(),
                    'unlinked_project_ids' => $projects->whereNull('redmine_project_id')->pluck('id')->values()->all(),
                ],
                severity: InsightSeverity::Info,
                kind: InsightKind::Reminder,
                fingerprint: self::TARGET_MISSING_FINGERPRINT,
                title: sprintf('%d 個結案中專案未設定目標結案日：%s', $names->count(), $names->implode('、')),
            );
        }

        return $firings;
    }
}
