<?php

namespace App\Observers;

use App\Domain\Notify\Notifier;
use App\Jobs\NotifyInsight;
use App\Models\Insight;

/**
 * Queue notification routing whenever a saved insight is still un-notified (new, or escalated — which clears
 * `notified_at`). Dispatched after the surrounding transaction commits.
 */
class InsightObserver
{
    public function __construct(protected Notifier $notifier) {}

    public function saved(Insight $insight): void
    {
        if ($this->notifier->insightIsPending($insight)) {
            NotifyInsight::dispatch($insight->getKey())->afterCommit();
        }
    }
}
