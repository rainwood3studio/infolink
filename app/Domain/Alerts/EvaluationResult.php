<?php

namespace App\Domain\Alerts;

use App\Models\SyncRun;

/**
 * The outcome of one evaluation pass over the active rules.
 */
final readonly class EvaluationResult
{
    /**
     * @param  list<RuleOutcome>  $outcomes
     */
    public function __construct(
        public array $outcomes,
        public bool $dryRun,
        public ?SyncRun $run = null,
    ) {}

    /**
     * @return array{rules: int, fired: int, raised: int, updated: int, resolved: int, errors: int}
     */
    public function stats(): array
    {
        $sum = fn (callable $count): int => array_sum(array_map($count, $this->outcomes));

        return [
            'rules' => count($this->outcomes),
            'fired' => $sum(fn (RuleOutcome $outcome): int => count($outcome->firings)),
            'raised' => $sum(fn (RuleOutcome $outcome): int => $outcome->count(RuleOutcome::RAISE)),
            'updated' => $sum(fn (RuleOutcome $outcome): int => $outcome->count(RuleOutcome::UPDATE)),
            'resolved' => $sum(fn (RuleOutcome $outcome): int => count($outcome->resolved)),
            'errors' => count(array_filter($this->outcomes, fn (RuleOutcome $outcome): bool => $outcome->error !== null)),
        ];
    }
}
