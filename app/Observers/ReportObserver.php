<?php

namespace App\Observers;

use App\Domain\Notify\Notifier;
use App\Jobs\NotifyReport;
use App\Models\Report;

/**
 * Queue the announcement of a report saved with `notify` and not yet notified, after the transaction commits.
 */
class ReportObserver
{
    public function __construct(protected Notifier $notifier) {}

    public function saved(Report $report): void
    {
        if ($this->notifier->reportIsPending($report)) {
            NotifyReport::dispatch($report->getKey())->afterCommit();
        }
    }
}
