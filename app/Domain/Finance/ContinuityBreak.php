<?php

namespace App\Domain\Finance;

use Carbon\CarbonImmutable;

/**
 * An imported statement line whose printed balance does not follow from the previous balance.
 */
final readonly class ContinuityBreak
{
    /**
     * @param  int  $rowIndex  Zero-based index of the offending row in the imported batch.
     */
    public function __construct(
        public int $rowIndex,
        public CarbonImmutable $txnDate,
        public string $summary,
        public int $previousBalance,
        public int $expectedBalance,
        public int $actualBalance,
    ) {}

    public function difference(): int
    {
        return $this->actualBalance - $this->expectedBalance;
    }

    /**
     * @return array{row_index:int, txn_date:string, summary:string, previous_balance:int, expected_balance:int, actual_balance:int, difference:int}
     */
    public function toArray(): array
    {
        return [
            'row_index' => $this->rowIndex,
            'txn_date' => $this->txnDate->toDateString(),
            'summary' => $this->summary,
            'previous_balance' => $this->previousBalance,
            'expected_balance' => $this->expectedBalance,
            'actual_balance' => $this->actualBalance,
            'difference' => $this->difference(),
        ];
    }
}
