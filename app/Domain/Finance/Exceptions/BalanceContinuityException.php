<?php

namespace App\Domain\Finance\Exceptions;

use App\Domain\Finance\ContinuityBreak;
use RuntimeException;

/**
 * Thrown by a strict import when the statement balances do not chain; the import is rolled back.
 */
class BalanceContinuityException extends RuntimeException
{
    /**
     * @param  list<ContinuityBreak>  $breaks
     */
    public function __construct(public readonly array $breaks)
    {
        $first = $breaks[0];

        parent::__construct(sprintf(
            'Balance continuity broken in %d row(s); first at row %d (%s): expected %d, got %d.',
            count($breaks),
            $first->rowIndex,
            $first->txnDate->toDateString(),
            $first->expectedBalance,
            $first->actualBalance,
        ));
    }
}
