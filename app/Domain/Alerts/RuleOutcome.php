<?php

namespace App\Domain\Alerts;

/**
 * What one rule did (or, in a dry run, would do) in one evaluation.
 */
final class RuleOutcome
{
    public const string RAISE = 'raise';

    public const string UPDATE = 'update';

    /**
     * @param  list<array{action: 'raise'|'update', fingerprint: string, severity: string, title: string}>  $firings
     * @param  list<array{insight_id: int, fingerprint: string, title: string}>  $resolved
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public array $firings = [],
        public array $resolved = [],
        public ?string $error = null,
    ) {}

    public function count(string $action): int
    {
        return count(array_filter($this->firings, fn (array $firing): bool => $firing['action'] === $action));
    }
}
