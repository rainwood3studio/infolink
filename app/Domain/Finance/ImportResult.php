<?php

namespace App\Domain\Finance;

/**
 * Outcome of a bank statement import.
 */
final readonly class ImportResult
{
    /**
     * @param  list<ContinuityBreak>  $continuityBreaks
     */
    public function __construct(
        public int $created,
        public int $updated,
        public int $skipped,
        public array $continuityBreaks = [],
    ) {}

    public function hasContinuityBreaks(): bool
    {
        return $this->continuityBreaks !== [];
    }

    /**
     * @return array{created:int, updated:int, skipped:int, continuity_breaks:list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'continuity_breaks' => array_map(fn (ContinuityBreak $break): array => $break->toArray(), $this->continuityBreaks),
        ];
    }
}
