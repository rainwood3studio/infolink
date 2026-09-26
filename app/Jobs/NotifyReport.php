<?php

namespace App\Jobs;

use App\Domain\Notify\Notifier;
use App\Models\Report;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Announce one report through the {@see Notifier} (dispatched after commit by the report observer).
 */
class NotifyReport implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $reportId) {}

    public function handle(Notifier $notifier): void
    {
        $report = Report::query()->find($this->reportId);

        if ($report !== null) {
            $notifier->notifyReport($report);
        }
    }
}
