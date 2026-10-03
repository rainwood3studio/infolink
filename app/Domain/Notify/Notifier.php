<?php

namespace App\Domain\Notify;

use App\Domain\Notify\Exceptions\LineNotConfiguredException;
use App\Domain\Notify\Exceptions\LineRequestException;
use App\Domain\Notify\Exceptions\LineUnavailableException;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Jobs\SendLineMessage;
use App\Models\AlertRule;
use App\Models\Insight;
use App\Models\NotificationLog;
use App\Models\Report;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Decides who hears about an insight or report, and how (docs/03-integration.md §6):
 *
 * - critical insight: LINE + bell right away, even in quiet hours; one push per fingerprint per dedupe window
 *   (an escalation of the same row clears `notified_at` and pushes again);
 * - warning insight: bell only (the daily brief summarises it);
 * - info insight: nothing;
 * - report with `notify`: bell right away, LINE summary now or — in quiet hours — deferred to when they end;
 * - anything else (the morning to-do digest): {@see sendLineOrDefer()}, same quiet-hours rule.
 *
 * Every attempt is written to `notification_logs`. `notified_at` is claimed atomically before sending, so
 * concurrent jobs for the same record never double-push.
 */
class Notifier
{
    public function __construct(
        protected LineMessenger $line,
        protected MessageFormatter $formatter,
        protected QuietHours $quietHours,
    ) {}

    /**
     * Whether a saved insight still needs routing (the observer's cheap pre-check; the job re-checks).
     */
    public function insightIsPending(Insight $insight): bool
    {
        return $insight->notified_at === null
            && in_array($insight->status, [InsightStatus::Open, InsightStatus::Acknowledged], true)
            && in_array($insight->severity, [InsightSeverity::Critical, InsightSeverity::Warning], true);
    }

    public function reportIsPending(Report $report): bool
    {
        return $report->notify && $report->notified_at === null;
    }

    public function notifyInsight(Insight $insight): void
    {
        $insight->refresh();

        if (! $this->insightIsPending($insight) || ! $this->claim($insight)) {
            return;
        }

        $pushesLine = $this->pushesLine($insight);

        if ($pushesLine && $this->pushedRecently($insight)) {
            $this->log(NotificationChannel::Line, NotificationStatus::Skipped, ['text' => $this->formatter->insightText($insight)], insight: $insight,
                error: sprintf('同一 fingerprint 在 %d 小時內已推播過', $this->dedupeHours()));

            return;
        }

        $this->sendToDatabase(
            title: $this->formatter->insightHeadline($insight),
            body: $this->formatter->insightExcerpt($insight),
            url: $this->formatter->insightUrl($insight),
            status: $insight->severity === InsightSeverity::Critical ? 'danger' : 'warning',
            insight: $insight,
        );

        if (! $pushesLine) {
            return;
        }

        $text = $this->formatter->insightText($insight);

        if ($insight->severity !== InsightSeverity::Critical && $this->quietHours->contains(now())) {
            $this->log(NotificationChannel::Line, NotificationStatus::Deferred, ['text' => $text], insight: $insight,
                deliverAfter: $this->quietHours->endAfter(now()));

            return;
        }

        SendLineMessage::dispatch($text, insightId: $insight->getKey());
    }

    /**
     * Critical insights always go to LINE. A warning goes to LINE only when the alert rule that raised it lists
     * `line` in its notify_channels (e.g. receivable-overdue), and then it respects quiet hours.
     */
    protected function pushesLine(Insight $insight): bool
    {
        if ($insight->severity === InsightSeverity::Critical) {
            return true;
        }

        $ruleKey = $insight->evidence['alert_rule'] ?? null;

        if ($insight->severity !== InsightSeverity::Warning || ! is_string($ruleKey)) {
            return false;
        }

        $channels = AlertRule::query()->where('key', $ruleKey)->value('notify_channels');

        return in_array(NotificationChannel::Line->value, (array) (is_string($channels) ? json_decode($channels, true) : $channels), true);
    }

    public function notifyReport(Report $report): void
    {
        $report->refresh();

        if (! $this->reportIsPending($report) || ! $this->claim($report)) {
            return;
        }

        $this->sendToDatabase(
            title: $this->formatter->reportHeadline($report),
            body: $this->formatter->reportSummary($report),
            url: $this->formatter->reportUrl($report),
            status: 'info',
            report: $report,
        );

        $text = $this->formatter->reportText($report);

        if ($this->quietHours->contains(now())) {
            $this->log(NotificationChannel::Line, NotificationStatus::Deferred, ['text' => $text], report: $report,
                deliverAfter: $this->quietHours->endAfter(now()));

            return;
        }

        SendLineMessage::dispatch($text, reportId: $report->getKey());
    }

    /**
     * Push one LINE message and log the attempt (updating `$log` in place when flushing a deferred one).
     * Never throws for LINE errors unless `$throwIfRetryable`, which lets a caller retry transient failures.
     *
     * @throws LineUnavailableException after logging, when LINE is down and `$throwIfRetryable` is set
     */
    public function sendLine(string $text, ?int $insightId = null, ?int $reportId = null, ?NotificationLog $log = null, bool $throwIfRetryable = false): NotificationLog
    {
        $log ??= new NotificationLog(['channel' => NotificationChannel::Line, 'insight_id' => $insightId, 'report_id' => $reportId]);
        $log->payload = ['text' => $text];

        try {
            $this->line->push($text);
        } catch (LineNotConfiguredException $exception) {
            return $this->finish($log, NotificationStatus::Skipped, $exception->getMessage());
        } catch (LineRequestException $exception) {
            return $this->finish($log, NotificationStatus::Failed, $exception->getMessage());
        } catch (LineUnavailableException $exception) {
            $this->finish($log, NotificationStatus::Failed, $exception->getMessage());

            if ($throwIfRetryable) {
                throw $exception;
            }

            return $log;
        }

        return $this->finish($log, NotificationStatus::Sent);
    }

    /**
     * Push a LINE message that belongs to no insight or report (e.g. the morning to-do digest): right away, or —
     * in quiet hours — logged as deferred for {@see flushDeferred()} to send when they end.
     */
    public function sendLineOrDefer(string $text): NotificationLog
    {
        if ($this->quietHours->contains(now())) {
            return $this->log(NotificationChannel::Line, NotificationStatus::Deferred, ['text' => $text],
                deliverAfter: $this->quietHours->endAfter(now()));
        }

        return $this->sendLine($text);
    }

    /**
     * Send every deferred LINE message whose `deliver_after` has passed. Transient failures are handed to a
     * retrying {@see SendLineMessage} job.
     *
     * @return int The number of deferred messages processed.
     */
    public function flushDeferred(): int
    {
        return NotificationLog::query()
            ->where('channel', NotificationChannel::Line)
            ->where('status', NotificationStatus::Deferred)
            ->where('deliver_after', '<=', now())
            ->orderBy('deliver_after')
            ->orderBy('id')
            ->get()
            ->each(function (NotificationLog $log): void {
                $text = (string) ($log->payload['text'] ?? '');

                try {
                    $this->sendLine($text, log: $log, throwIfRetryable: true);
                } catch (LineUnavailableException) {
                    SendLineMessage::dispatch($text, $log->insight_id, $log->report_id)->delay(now()->addMinute());
                }
            })
            ->count();
    }

    /**
     * Bell notification for every user (single-owner app), logged as one row.
     */
    public function sendToDatabase(string $title, ?string $body, ?string $url, string $status = 'info', ?Insight $insight = null, ?Report $report = null): NotificationLog
    {
        $payload = array_filter(['title' => $title, 'body' => $body, 'url' => $url], fn (?string $value): bool => filled($value));

        /** @var Collection<int, User> $users */
        $users = User::query()->get();

        if ($users->isEmpty()) {
            return $this->log(NotificationChannel::Database, NotificationStatus::Skipped, $payload, $insight, $report, error: 'No users to notify');
        }

        $notification = Notification::make()
            ->title($title)
            ->body($body)
            ->status($status);

        if (filled($url)) {
            $notification->actions([
                Action::make('view')
                    ->label('查看')
                    ->url($url)
                    ->markAsRead(),
            ]);
        }

        $notification->sendToDatabase($users);

        return $this->log(NotificationChannel::Database, NotificationStatus::Sent, $payload, $insight, $report, sentAt: true);
    }

    /**
     * Mark the record notified only if nobody else did first.
     */
    protected function claim(Insight|Report $record): bool
    {
        $claimed = $record->newQuery()
            ->whereKey($record->getKey())
            ->whereNull('notified_at')
            ->update(['notified_at' => now()]) === 1;

        if ($claimed) {
            $record->notified_at = now();
            $record->syncOriginalAttribute('notified_at');
        }

        return $claimed;
    }

    /**
     * Whether another insight with the same fingerprint was delivered within the dedupe window.
     */
    protected function pushedRecently(Insight $insight): bool
    {
        return NotificationLog::query()
            ->where('status', NotificationStatus::Sent)
            ->where('sent_at', '>=', now()->subHours($this->dedupeHours()))
            ->where('insight_id', '!=', $insight->getKey())
            ->whereHas('insight', fn ($query) => $query->where('fingerprint', $insight->fingerprint))
            ->exists();
    }

    protected function dedupeHours(): int
    {
        return (int) config('infolink.notifications.dedupe_hours', 24);
    }

    protected function finish(NotificationLog $log, NotificationStatus $status, ?string $error = null): NotificationLog
    {
        $log->fill([
            'status' => $status,
            'error' => $error,
            'sent_at' => $status === NotificationStatus::Sent ? now() : null,
        ])->save();

        return $log;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function log(
        NotificationChannel $channel,
        NotificationStatus $status,
        array $payload,
        ?Insight $insight = null,
        ?Report $report = null,
        ?string $error = null,
        mixed $deliverAfter = null,
        bool $sentAt = false,
    ): NotificationLog {
        return NotificationLog::query()->create([
            'channel' => $channel,
            'insight_id' => $insight?->getKey(),
            'report_id' => $report?->getKey(),
            'status' => $status,
            'payload' => $payload,
            'error' => $error,
            'deliver_after' => $deliverAfter,
            'sent_at' => $sentAt ? now() : null,
        ]);
    }
}
