<?php

namespace App\Console\Commands;

use App\Domain\Alerts\RuleEvaluator;
use App\Domain\Alerts\RuleOutcome;
use App\Models\AlertRule;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('infolink:evaluate-rules
    {--rule= : Only evaluate the active rule with this key}
    {--dry-run : Show what would fire / resolve without writing anything}')]
#[Description('Evaluate the active alert rules: raise insights for what fires, auto-resolve what cleared')]
class EvaluateRules extends Command
{
    public function handle(RuleEvaluator $evaluator): int
    {
        $ruleKey = $this->option('rule') !== null ? (string) $this->option('rule') : null;

        if ($ruleKey !== null && ! AlertRule::query()->where('key', $ruleKey)->where('is_active', true)->exists()) {
            $this->components->error("No active alert rule [{$ruleKey}].");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $result = $evaluator->evaluate($ruleKey, $dryRun);
        $rows = [];

        foreach ($result->outcomes as $outcome) {
            if ($outcome->error !== null) {
                $rows[] = [$outcome->key, 'ERROR', '', '', $outcome->error];
            }

            foreach ($outcome->firings as $firing) {
                $rows[] = [$outcome->key, $firing['action'] === RuleOutcome::RAISE ? 'new' : 'update', $firing['severity'], $firing['fingerprint'], $firing['title']];
            }

            foreach ($outcome->resolved as $resolved) {
                $rows[] = [$outcome->key, 'resolve', '', $resolved['fingerprint'], $resolved['title']];
            }
        }

        if ($rows !== []) {
            $this->table(['Rule', 'Action', 'Severity', 'Fingerprint', 'Title'], $rows);
        }

        $stats = $result->stats();
        $summary = sprintf(
            '%s%d rule(s): %d firing (%d new, %d updated), %d resolved%s.',
            $dryRun ? '[dry run] ' : '',
            $stats['rules'],
            $stats['fired'],
            $stats['raised'],
            $stats['updated'],
            $stats['resolved'],
            $stats['errors'] > 0 ? ", {$stats['errors']} error(s)" : '',
        );

        $stats['errors'] > 0 ? $this->components->warn($summary) : $this->components->info($summary);

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
