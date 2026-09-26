<?php

namespace App\Console\Commands;

use App\Domain\Sales\SalesMetrics;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('infolink:snapshot-sales')]
#[Description('Record today\'s sales pipeline metrics, then evaluate the alert rules')]
class SnapshotSales extends Command
{
    public function handle(SalesMetrics $salesMetrics): int
    {
        $salesMetrics->record();

        $this->components->info('Recorded sales metrics as of '.today()->toDateString().'.');

        $this->call('infolink:evaluate-rules');

        return self::SUCCESS;
    }
}
