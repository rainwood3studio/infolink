<?php

namespace App\Domain\Sales;

use App\Enums\DealEventType;
use App\Enums\DealStage;
use App\Enums\Source;
use App\Models\Deal;
use App\Models\DealEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single write path for deals and their history, plus the pipeline summary.
 *
 * Stage changes always go through {@see changeStage()} so every move is recorded as a `stage_change` event and the
 * closed state stays consistent: won → probability 100, lost → probability 0, both set `closed_at`; reopening clears it.
 */
class DealService
{
    /**
     * Create or update a deal. Matched, in order, by an `id` attribute, by `$externalKey` (any source), or by
     * customer (or prospect name when there is no customer) + title; otherwise a new deal is created.
     *
     * A `stage` attribute on an existing deal is applied through {@see changeStage()} (logging an event, with
     * `$stageNote` / `$stageChangedOn`). `closed_at` is managed here and never taken from the attributes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsert(
        array $attributes,
        Source $source = Source::Manual,
        ?string $externalKey = null,
        ?string $stageNote = null,
        ?CarbonInterface $stageChangedOn = null,
    ): Deal {
        $stage = isset($attributes['stage']) ? self::stage($attributes['stage']) : null;
        $existing = $this->findExisting($attributes, $externalKey);

        unset($attributes['id'], $attributes['stage'], $attributes['closed_at'], $attributes['source'], $attributes['external_key']);

        return DB::transaction(function () use ($existing, $attributes, $stage, $source, $externalKey, $stageNote, $stageChangedOn): Deal {
            if ($existing === null) {
                return $this->create($attributes, $stage ?? DealStage::Lead, $source, $externalKey, $stageChangedOn);
            }

            $existing->fill($attributes);

            if ($externalKey !== null && $existing->external_key === null) {
                $existing->external_key = $externalKey;
            }

            $existing->save();

            if ($stage !== null) {
                $this->changeStage($existing, $stage, $stageNote, $stageChangedOn, $source);
            }

            return $existing;
        });
    }

    /**
     * Move a deal to another stage and record a `stage_change` event (from → to). Won sets probability 100, lost 0;
     * both set `closed_at` (the given date, or now); moving back to an open stage clears `closed_at`.
     * Returns null (and changes nothing) when the deal is already in that stage.
     */
    public function changeStage(
        Deal $deal,
        DealStage $stage,
        ?string $note = null,
        ?CarbonInterface $occurredOn = null,
        Source $source = Source::Manual,
    ): ?DealEvent {
        if ($deal->stage === $stage) {
            return null;
        }

        return DB::transaction(function () use ($deal, $stage, $note, $occurredOn, $source): DealEvent {
            $from = $deal->stage;

            $deal->stage = $stage;
            $this->applyClosedState($deal, $occurredOn);
            $deal->save();

            return DealEvent::query()->create([
                'deal_id' => $deal->id,
                'occurred_on' => $occurredOn ?? today(),
                'type' => DealEventType::StageChange,
                'content' => $note,
                'from_stage' => $from,
                'to_stage' => $stage,
                'source' => $source,
            ]);
        });
    }

    /**
     * Record a meeting, proposal or note on a deal. With an external key the call is idempotent: the same deal + key
     * updates the existing event. Stage changes are not logged here; use {@see changeStage()}.
     */
    public function logEvent(
        Deal $deal,
        DealEventType $type,
        ?string $content,
        ?CarbonInterface $occurredOn = null,
        Source $source = Source::Manual,
        ?string $externalKey = null,
    ): DealEvent {
        if ($type === DealEventType::StageChange) {
            throw new InvalidArgumentException('Stage changes are recorded by changing the deal stage, not logged directly.');
        }

        $attributes = [
            'occurred_on' => $occurredOn ?? today(),
            'type' => $type,
            'content' => $content,
        ];

        $event = $externalKey === null
            ? null
            : DealEvent::query()->where('deal_id', $deal->id)->where('external_key', $externalKey)->first();

        if ($event !== null) {
            $event->update($attributes);

            return $event;
        }

        return DealEvent::query()->create([
            ...$attributes,
            'deal_id' => $deal->id,
            'source' => $source,
            'external_key' => $externalKey,
        ]);
    }

    /**
     * The open pipeline as of `$today`: totals, a per-stage breakdown (every open stage, zeros included) and the
     * open deals without a usable next action (missing first, then oldest `next_action_on`).
     *
     * @return array{open_count: int, amount_total: int, weighted_total: int, by_stage: array<string, array{label: string, count: int, amount: int, weighted: int}>, no_next_action: Collection<int, Deal>}
     */
    public function pipeline(?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::instance($today ?? today())->startOfDay();
        $deals = Deal::query()->open()->with('customer')->orderBy('id')->get();

        return [
            'open_count' => $deals->count(),
            'amount_total' => (int) $deals->sum('amount_untaxed'),
            'weighted_total' => (int) $deals->sum(fn (Deal $deal): int => $deal->weighted_amount),
            'by_stage' => collect(DealStage::open())->mapWithKeys(function (DealStage $stage) use ($deals): array {
                $inStage = $deals->where('stage', $stage);

                return [$stage->value => [
                    'label' => $stage->getLabel(),
                    'count' => $inStage->count(),
                    'amount' => (int) $inStage->sum('amount_untaxed'),
                    'weighted' => (int) $inStage->sum(fn (Deal $deal): int => $deal->weighted_amount),
                ]];
            })->all(),
            'no_next_action' => $deals
                ->filter(fn (Deal $deal): bool => self::needsNextAction($deal, $today))
                ->sortBy(fn (Deal $deal): string => $deal->next_action_on?->toDateString() ?? '0000-00-00')
                ->values(),
        ];
    }

    /**
     * An open deal whose next action is missing or dated before `$today` (see Deal::scopeWithoutNextAction()).
     */
    public static function needsNextAction(Deal $deal, ?CarbonInterface $today = null): bool
    {
        $today ??= today();

        return ! $deal->stage->isClosed()
            && ($deal->next_action_on === null || $deal->next_action_on->lt($today->copy()->startOfDay()));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function create(array $attributes, DealStage $stage, Source $source, ?string $externalKey, ?CarbonInterface $closedOn): Deal
    {
        if (blank($attributes['title'] ?? null)) {
            throw new InvalidArgumentException('A deal needs a title.');
        }

        if (blank($attributes['customer_id'] ?? null) && blank($attributes['prospect_name'] ?? null)) {
            throw new InvalidArgumentException('A deal needs a customer or a prospect name.');
        }

        $deal = new Deal([...$attributes, 'stage' => $stage, 'source' => $source, 'external_key' => $externalKey]);
        $this->applyClosedState($deal, $closedOn);
        $deal->save();

        return $deal;
    }

    /**
     * The deal {@see upsert()} would update for these attributes, or null when it would create one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function findExisting(array $attributes, ?string $externalKey = null): ?Deal
    {
        if (isset($attributes['id'])) {
            return Deal::query()->findOrFail($attributes['id']);
        }

        if ($externalKey !== null && ($byKey = Deal::query()->where('external_key', $externalKey)->orderBy('id')->first()) !== null) {
            return $byKey;
        }

        if (blank($attributes['title'] ?? null)) {
            return null;
        }

        if (filled($attributes['customer_id'] ?? null)) {
            return Deal::query()->where('customer_id', $attributes['customer_id'])->where('title', $attributes['title'])->orderBy('id')->first();
        }

        if (filled($attributes['prospect_name'] ?? null)) {
            return Deal::query()
                ->whereNull('customer_id')
                ->where('prospect_name', $attributes['prospect_name'])
                ->where('title', $attributes['title'])
                ->orderBy('id')
                ->first();
        }

        return null;
    }

    /**
     * Keep probability and `closed_at` consistent with the deal's (new) stage.
     */
    protected function applyClosedState(Deal $deal, ?CarbonInterface $occurredOn): void
    {
        if (! $deal->stage->isClosed()) {
            $deal->closed_at = null;

            return;
        }

        $deal->probability = $deal->stage === DealStage::Won ? 100 : 0;
        $deal->closed_at = $occurredOn === null || $occurredOn->isToday() ? now() : CarbonImmutable::instance($occurredOn)->startOfDay();
    }

    protected static function stage(DealStage|string $stage): DealStage
    {
        return $stage instanceof DealStage ? $stage : DealStage::from($stage);
    }
}
