<?php

namespace App\Mcp\Tools;

use App\Domain\Sales\DealService;
use App\Enums\DealStage;
use App\Mcp\Tools\Concerns\PresentsDeals;
use App\Models\Deal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_deals')]
#[Description(<<<'TEXT'
List sales opportunities (業務機會), open ones by default, ordered by stage (lead → proposal → negotiation → won → lost) then expected close date.
Amounts are UNTAXED whole NTD; `weighted_amount` = amount_untaxed × probability / 100. `party` is the customer short name, or the prospect name when not yet a customer.
`needs_next_action` is true for an open deal whose next_action_on is empty or already past (these are flagged by the 業務斷層 rule after 7 days). `days_since_last_event` counts from the latest history entry (null = no history).
Each deal carries its 3 most recent events (meeting / proposal_sent / stage_change / note). `pipeline` sums the OPEN pipeline regardless of filters.
TEXT)]
class ListDeals extends ReadTool
{
    use PresentsDeals;

    public const int MAX_ROWS = 200;

    public const int RECENT_EVENTS = 3;

    public function handle(Request $request, DealService $deals): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'stage' => ['nullable', Rule::enum(DealStage::class)],
            'open_only' => ['nullable', 'boolean'],
            'no_next_action' => ['nullable', 'boolean'],
            'party' => ['nullable', 'string', 'max:255'],
        ]);

        $stage = isset($validated['stage']) ? DealStage::from($validated['stage']) : null;
        $openOnly = $stage === null && ($validated['open_only'] ?? true);
        $noNextAction = (bool) ($validated['no_next_action'] ?? false);
        $pipeline = $deals->pipeline();

        $rows = Deal::query()
            ->with(['customer', 'events'])
            ->when($stage !== null, fn (Builder $query) => $query->where('stage', $stage))
            ->when($openOnly, fn (Builder $query) => $query->open())
            ->when($noNextAction, fn (Builder $query) => $query->withoutNextAction())
            ->when(filled($validated['party'] ?? null), fn (Builder $query) => $query->where(fn (Builder $party) => $party
                ->whereLike('prospect_name', '%'.$validated['party'].'%')
                ->orWhereHas('customer', fn (Builder $customer) => $customer
                    ->whereLike('short_name', '%'.$validated['party'].'%')
                    ->orWhereLike('name', '%'.$validated['party'].'%'))))
            ->orderByRaw(self::stageOrder())
            ->orderByRaw('case when expected_close_on is null then 1 else 0 end')
            ->orderBy('expected_close_on')
            ->orderBy('id')
            ->limit(self::MAX_ROWS)
            ->get();

        return Response::json([
            'filters' => [
                'stage' => $stage?->value,
                'open_only' => $openOnly,
                'no_next_action' => $noNextAction,
                'party' => $validated['party'] ?? null,
            ],
            'pipeline' => [
                'open_count' => $pipeline['open_count'],
                'amount_total' => $pipeline['amount_total'],
                'weighted_total' => $pipeline['weighted_total'],
                'by_stage' => $pipeline['by_stage'],
                'no_next_action_count' => $pipeline['no_next_action']->count(),
            ],
            'count' => $rows->count(),
            'deals' => $rows->map(fn (Deal $deal): array => self::presentDeal($deal, self::RECENT_EVENTS))->all(),
        ]);
    }

    protected static function stageOrder(): string
    {
        $cases = collect(DealStage::cases())
            ->map(fn (DealStage $stage, int $index): string => "when '{$stage->value}' then {$index}")
            ->implode(' ');

        return "case stage {$cases} else 99 end";
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'stage' => $schema->string()->enum(array_column(DealStage::cases(), 'value'))->description('Only this stage (overrides open_only).'),
            'open_only' => $schema->boolean()->description('Only lead / proposal / negotiation. Default true; false includes won and lost.'),
            'no_next_action' => $schema->boolean()->description('Only open deals whose next action is missing or past. Default false.'),
            'party' => $schema->string()->description('Partial match on customer short name / name or prospect name, e.g. 多羅滿.'),
        ];
    }
}
