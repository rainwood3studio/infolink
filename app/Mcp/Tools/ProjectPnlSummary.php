<?php

namespace App\Mcp\Tools;

use App\Domain\Engineering\GithubSync;
use App\Domain\Sales\ProjectPnl;
use App\Enums\SyncJob;
use App\Mcp\Tools\Concerns\PresentsRecords;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('project_pnl')]
#[Description(<<<'TEXT'
Project P&L and effort (the 專案損益 page): where the team's time went in the last `days` days per customer and project, next to the money each one brings in. Amounts are whole NTD, UNTAXED unless the key says `taxed`.
- Effort unit = PERSON-DAYS, not commits or lines. A person is a developer (all their GitHub identities) or an identity not mapped to one (`未對應：…`). Each calendar day (Asia/Taipei) on which a person authored at least one non-merge commit is one person-day, split equally among the distinct targets they committed to that day. `share` / `effort_share` are fractions (0–1) of `total_person_days`. `by_person` rows are `[name, person_days, commits]`; `repos` maps repo → commits.
- A commit's target comes from the repo mapping kept by hand: a per-branch project override, else the repo's project, else the repo's deal, else nothing.
- `estimated_cost` is an ESTIMATE, never an actual: `monthly_cost` (latest cost baseline) × days / 30 = `period_cost`, spread over the targets by person-days (the parts add up to `period_cost`). It spreads the WHOLE monthly cost over commit days: there is no per-person cost, and work that leaves no commit (acceptance, meetings, sales) is invisible. Always call it an estimate.
- `projects` (most effort first; only projects with commits, Redmine hours or money in the window — `omitted_projects` counts the rest): `contract_amount_untaxed` (null = not entered, NOT zero), `revenue_in_period` = receivables whose expected date falls in the window (any status except cancelled, recurring fees included — what the window was billed for, collected or not), `recurring_monthly` = the recurring fee of the latest month that has one (null = none), `margin_estimate` = revenue_in_period − estimated_cost (null when nothing is expected in the window or the cost is unknown; for a maintenance project this answers whether the fee covers the effort), `received_in_period` / `received_total` (status received), `outstanding_untaxed` / `outstanding_taxed`, `redmine_linked`, `redmine_hours` (hours logged in the window on the linked Redmine project; null = not linked).
- `customers`: the same rolled up per customer — `effort_share` against `received_share` (the customer's part of all money ever received, `received_total`) is the concentration picture. Effort on deals is not attributed to a customer.
- `presales`: effort on repos tied to a deal instead of a project — work for a prospect with no contract yet. Nobody is paying for these person-days; `presales_person_days` / `presales_share` / `presales_estimated_cost` total them.
- `unmapped`: repos with commits in the window tied to nothing (`branches` = commits per branch). Their effort is missing from every project and customer figure; `unmapped_share` (person-days) and `unmapped_commit_share` say how much. When this is large, say the per-project numbers are understated and name the repos to map.
- `missing`: `cost_baseline` (true = no baseline, every cost is null), and the projects with effort that have no contract amount / no Redmine project linked.
Caveats: GitHub commits are kept for one month only (`window.retention_start`; an earlier `from` is incomplete). Most of the CEO's commits are AI-assisted, so never rank or compare people by commits or lines — person-days only say where a day went. Redmine hours are logged by few people: reference only.
TEXT)]
class ProjectPnlSummary extends ReadTool
{
    use PresentsRecords;

    public const int DEFAULT_DAYS = 30;

    public const int MIN_DAYS = 7;

    public const int MAX_DAYS = 31;

    public function handle(Request $request, ProjectPnl $pnl): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:'.self::MIN_DAYS, 'max:'.self::MAX_DAYS],
        ]);

        $days = (int) ($validated['days'] ?? self::DEFAULT_DAYS);
        $today = CarbonImmutable::today(config('app.timezone'));
        $result = $pnl->calculate($today->subDays($days - 1), $today->endOfDay());

        return Response::json([
            'generated_at' => now()->toIso8601String(),
            'window' => [...$result['period'], 'retention_start' => GithubSync::windowStart()->toDateString()],
            'sync' => [
                SyncJob::GithubActivity->value => static::presentSyncJob(SyncJob::GithubActivity),
                SyncJob::RedmineTime->value => static::presentSyncJob(SyncJob::RedmineTime),
            ],
            ...Arr::except($result, ['period', 'projects', 'presales', 'unmapped']),
            'projects' => array_map(fn (array $row): array => $this->compact(Arr::except($row, ['customer_id', 'status_label'])), $result['projects']),
            'presales' => array_map(fn (array $row): array => $this->compact(Arr::except($row, ['stage_label'])), $result['presales']),
            'unmapped' => array_map(fn (array $row): array => $this->compact(Arr::except($row, ['repo_id', 'url'])), $result['unmapped']),
        ]);
    }

    /**
     * `by_person` as `[name, person_days, commits]` rows and `repos` as a repo → commits map.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function compact(array $row): array
    {
        $row['by_person'] = array_map(fn (array $person): array => [$person['name'], $person['person_days'], $person['commits']], $row['by_person']);

        if (isset($row['repos'])) {
            $row['repos'] = array_column($row['repos'], 'commits', 'full_name');
        }

        return $row;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()
                ->min(self::MIN_DAYS)
                ->max(self::MAX_DAYS)
                ->description('Length of the window in days, ending today. 7–31, default 30 (GitHub commits are only kept for one month).'),
        ];
    }
}
