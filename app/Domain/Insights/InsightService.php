<?php

namespace App\Domain\Insights;

use App\Domain\Work\ActionItemService;
use App\Enums\ActionItemPriority;
use App\Enums\InsightKind;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Enums\Source;
use App\Models\ActionItem;
use App\Models\Insight;
use InvalidArgumentException;

/**
 * The single write path for insights. Deduplicates on `fingerprint` while an insight is unresolved, so rules and
 * Claude can raise the same thing repeatedly without piling up rows.
 */
class InsightService
{
    /**
     * Content fields that a repeated raise refreshes on the existing unresolved insight.
     *
     * @var list<string>
     */
    protected const array REFRESHABLE = ['kind', 'category', 'title', 'body', 'evidence', 'severity', 'expires_at', 'vault_ref', 'notes'];

    public function __construct(protected ActionItemService $actionItems) {}

    /**
     * Create an insight, or refresh the unresolved (open/acknowledged) one with the same fingerprint.
     *
     * On refresh the status and `first_seen_at` are kept, `last_seen_at` is bumped, and only the given content fields
     * are overwritten. If the severity escalates (e.g. warning → critical), an acknowledged insight is reopened and
     * `notified_at` is cleared: the acknowledgement was for the lesser problem, and the Notifier should push again.
     * The original `source` is kept (the `actor` column records the latest writer).
     *
     * A resolved or dismissed insight is never revived; the same fingerprint then creates a new row.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function raise(array $attributes, Source $source = Source::System): Insight
    {
        $fingerprint = $attributes['fingerprint'] ?? null;

        if (blank($fingerprint)) {
            throw new InvalidArgumentException('An insight needs a fingerprint.');
        }

        $existing = Insight::query()
            ->unresolved()
            ->where('fingerprint', $fingerprint)
            ->latest('id')
            ->first();

        return $existing !== null
            ? $this->refresh($existing, $attributes)
            : $this->create($attributes, $source);
    }

    public function acknowledge(Insight $insight): Insight
    {
        if ($insight->status === InsightStatus::Open) {
            $insight->update(['status' => InsightStatus::Acknowledged]);
        }

        return $insight;
    }

    /**
     * Close an insight; the optional note (the reason) is appended to `notes`.
     */
    public function resolve(Insight $insight, ?string $note = null): Insight
    {
        return $this->close($insight, InsightStatus::Resolved, $note);
    }

    public function dismiss(Insight $insight, ?string $note = null): Insight
    {
        return $this->close($insight, InsightStatus::Dismissed, $note);
    }

    /**
     * Resolve every unresolved insight with this fingerprint.
     *
     * @return int The number of insights resolved.
     */
    public function resolveByFingerprint(string $fingerprint, ?string $note = null): int
    {
        return Insight::query()
            ->unresolved()
            ->where('fingerprint', $fingerprint)
            ->get()
            ->each(fn (Insight $insight): Insight => $this->resolve($insight, $note))
            ->count();
    }

    /**
     * Resolve unresolved insights whose `expires_at` has passed (e.g. "this week" reminders).
     *
     * @return int The number of insights expired.
     */
    public function expireStale(): int
    {
        return Insight::query()
            ->unresolved()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get()
            ->each(fn (Insight $insight): Insight => $this->resolve($insight, '已過期，自動結案'))
            ->count();
    }

    /**
     * Create an action item linked to the insight. Title defaults to the insight's; priority follows severity
     * (critical → P1, warning → P2, info → P3).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createActionItem(Insight $insight, array $attributes = [], Source $source = Source::Manual): ActionItem
    {
        return $this->actionItems->create([
            'title' => $insight->title,
            'priority' => match ($insight->severity) {
                InsightSeverity::Critical => ActionItemPriority::P1,
                InsightSeverity::Warning => ActionItemPriority::P2,
                InsightSeverity::Info => ActionItemPriority::P3,
            },
            ...$attributes,
            'related_type' => $insight->getMorphClass(),
            'related_id' => $insight->getKey(),
        ], $source);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function create(array $attributes, Source $source): Insight
    {
        if (blank($attributes['title'] ?? null) || blank($attributes['category'] ?? null)) {
            throw new InvalidArgumentException('A new insight needs a title and a category.');
        }

        return Insight::query()->create([
            'kind' => InsightKind::Observation,
            'severity' => InsightSeverity::Info,
            ...$attributes,
            'status' => InsightStatus::Open,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'resolved_at' => null,
            'source' => $source,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function refresh(Insight $insight, array $attributes): Insight
    {
        $previousSeverity = $insight->severity;

        $insight->fill(array_intersect_key($attributes, array_flip(self::REFRESHABLE)));
        $insight->last_seen_at = now();

        if ($this->rank($insight->severity) > $this->rank($previousSeverity)) {
            $insight->status = InsightStatus::Open;
            $insight->notified_at = null;
        }

        $insight->save();

        return $insight;
    }

    protected function close(Insight $insight, InsightStatus $status, ?string $note): Insight
    {
        $insight->fill([
            'status' => $status,
            'resolved_at' => now(),
            'notes' => filled($note) ? trim(($insight->notes ?? '')."\n".$note) : $insight->notes,
        ])->save();

        return $insight;
    }

    protected function rank(InsightSeverity $severity): int
    {
        return match ($severity) {
            InsightSeverity::Critical => 3,
            InsightSeverity::Warning => 2,
            InsightSeverity::Info => 1,
        };
    }
}
