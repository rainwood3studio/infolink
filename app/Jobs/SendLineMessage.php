<?php

namespace App\Jobs;

use App\Domain\Notify\Exceptions\LineUnavailableException;
use App\Domain\Notify\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Push one LINE message. Each attempt is logged; only transient failures (5xx, connection) are retried.
 * A 4xx or a missing configuration is logged once and not retried.
 */
class SendLineMessage implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * Seconds to wait before the 2nd and 3rd attempt.
     *
     * @var list<int>
     */
    public array $backoff = [60, 300];

    public function __construct(
        public string $text,
        public ?int $insightId = null,
        public ?int $reportId = null,
    ) {}

    public function handle(Notifier $notifier): void
    {
        try {
            $notifier->sendLine($this->text, $this->insightId, $this->reportId, throwIfRetryable: true);
        } catch (LineUnavailableException) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? end($this->backoff));
            }
        }
    }
}
