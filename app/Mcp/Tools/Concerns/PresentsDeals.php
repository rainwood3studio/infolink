<?php

namespace App\Mcp\Tools\Concerns;

use App\Domain\Sales\DealService;
use App\Models\Deal;
use App\Models\DealEvent;

/**
 * Shared JSON shapes for deals and their events (list_deals, upsert_deal, log_deal_event, get_briefing).
 */
trait PresentsDeals
{
    /**
     * @param  int  $recentEvents  How many of the latest events to include (0 = none).
     * @return array<string, mixed>
     */
    protected static function presentDeal(Deal $deal, int $recentEvents = 0): array
    {
        $deal->loadMissing(['customer', 'events']);
        $lastEvent = $deal->events->first();

        return [
            'id' => $deal->id,
            'party' => $deal->party_name,
            'customer' => $deal->customer?->short_name,
            'prospect_name' => $deal->prospect_name,
            'title' => $deal->title,
            'stage' => $deal->stage->value,
            'amount_untaxed' => $deal->amount_untaxed,
            'probability' => $deal->probability,
            'weighted_amount' => $deal->weighted_amount,
            'recurring_monthly' => $deal->recurring_monthly,
            'expected_close_on' => $deal->expected_close_on?->toDateString(),
            'next_action' => $deal->next_action,
            'next_action_on' => $deal->next_action_on?->toDateString(),
            'needs_next_action' => DealService::needsNextAction($deal),
            'last_event_on' => $lastEvent?->occurred_on->toDateString(),
            'days_since_last_event' => $lastEvent === null ? null : (int) $lastEvent->occurred_on->diffInDays(today()),
            'closed_at' => $deal->closed_at?->toDateString(),
            'external_key' => $deal->external_key,
            'source' => $deal->source->value,
            'notes' => $deal->notes,
            'vault_ref' => $deal->vault_ref,
            ...($recentEvents > 0 ? [
                'recent_events' => $deal->events->take($recentEvents)->map(fn (DealEvent $event): array => static::presentDealEvent($event))->values()->all(),
            ] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function presentDealEvent(DealEvent $event): array
    {
        return [
            'id' => $event->id,
            'occurred_on' => $event->occurred_on->toDateString(),
            'type' => $event->type->value,
            'content' => $event->content,
            'from_stage' => $event->from_stage?->value,
            'to_stage' => $event->to_stage?->value,
            'external_key' => $event->external_key,
            'source' => $event->source->value,
            'actor' => $event->actor,
        ];
    }
}
