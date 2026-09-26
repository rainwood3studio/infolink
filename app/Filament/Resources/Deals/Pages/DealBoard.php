<?php

namespace App\Filament\Resources\Deals\Pages;

use App\Domain\Sales\DealService;
use App\Enums\DealStage;
use App\Filament\Resources\Deals\Actions\DealActions;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * 業務機會看板: one column per open stage, won/lost collapsed to counts. Cards move by the 移動 action or drag-and-drop.
 */
class DealBoard extends Page
{
    protected static string $resource = DealResource::class;

    protected string $view = 'filament.resources.deals.pages.deal-board';

    protected static ?string $title = '業務機會看板';

    protected static ?string $breadcrumb = '看板';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('table')
                ->label('表格')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->url(DealResource::getUrl('index')),
            CreateAction::make()
                ->url(DealResource::getUrl('create')),
        ];
    }

    /**
     * Open stages with their deals, next action date first (missing dates last).
     *
     * @return list<array{stage:DealStage, deals:Collection<int, Deal>, amount:int, weighted:int}>
     */
    public function openColumns(): array
    {
        $deals = Deal::query()
            ->with('customer')
            ->open()
            ->orderByRaw('next_action_on is null')
            ->orderBy('next_action_on')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (Deal $deal): string => $deal->stage->value);

        return array_map(function (DealStage $stage) use ($deals): array {
            $stageDeals = $deals->get($stage->value, collect());

            return [
                'stage' => $stage,
                'deals' => $stageDeals,
                'amount' => (int) $stageDeals->sum('amount_untaxed'),
                'weighted' => (int) $stageDeals->sum(fn (Deal $deal): int => $deal->weighted_amount),
            ];
        }, DealStage::open());
    }

    /**
     * 成交 / 未成交 as counts only, each linking to the filtered table.
     *
     * @return list<array{stage:DealStage, count:int, amount:int, url:string}>
     */
    public function closedColumns(): array
    {
        return array_map(fn (DealStage $stage): array => [
            'stage' => $stage,
            'count' => Deal::query()->where('stage', $stage)->count(),
            'amount' => (int) Deal::query()->where('stage', $stage)->sum('amount_untaxed'),
            'url' => DealResource::getUrl('index', [
                'filters' => ['open' => ['isActive' => false], 'stage' => ['values' => [$stage->value]]],
            ]),
        ], [DealStage::Won, DealStage::Lost]);
    }

    public function moveStageAction(): Action
    {
        return DealActions::advanceStage('moveStage')
            ->label('移動')
            ->record(fn (array $arguments): ?Deal => Deal::query()->find($arguments['deal'] ?? null))
            ->link()
            ->size(Size::Small);
    }

    /**
     * Drag-and-drop target: move a card to the column it was dropped on.
     */
    public function moveDeal(int $dealId, string $stage): void
    {
        $deal = Deal::query()->findOrFail($dealId);
        $target = DealStage::from($stage);

        if ($deal->stage === $target) {
            return;
        }

        app(DealService::class)->changeStage($deal, $target);

        Notification::make()->title("已移到「{$target->getLabel()}」")->success()->send();
    }
}
