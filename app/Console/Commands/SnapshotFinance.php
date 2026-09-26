<?php

namespace App\Console\Commands;

use App\Domain\Finance\FinancePosition;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('infolink:snapshot-finance')]
#[Description('Record today\'s finance metrics and store a cash forecast snapshot, then evaluate the alert rules')]
class SnapshotFinance extends Command
{
    public function handle(FinancePosition $financePosition): int
    {
        $forecast = $financePosition->recordSnapshot();

        if ($forecast === null) {
            $this->components->warn('No bank transactions yet; nothing recorded.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Recorded finance metrics as of %s; forecast year-end NT$%s.',
            $forecast->as_of->toDateString(),
            number_format($forecast->year_end_balance),
        ));

        $this->call('infolink:evaluate-rules');

        return self::SUCCESS;
    }
}
