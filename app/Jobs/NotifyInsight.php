<?php

namespace App\Jobs;

use App\Domain\Notify\Notifier;
use App\Models\Insight;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Route one insight through the {@see Notifier} (dispatched after commit by the insight observer).
 */
class NotifyInsight implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $insightId) {}

    public function handle(Notifier $notifier): void
    {
        $insight = Insight::query()->find($this->insightId);

        if ($insight !== null) {
            $notifier->notifyInsight($insight);
        }
    }
}
