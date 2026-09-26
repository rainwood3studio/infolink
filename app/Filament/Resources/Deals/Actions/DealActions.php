<?php

namespace App\Filament\Resources\Deals\Actions;

use App\Domain\Sales\DealService;
use App\Enums\DealEventType;
use App\Enums\DealStage;
use App\Models\Deal;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * 推進階段 / 記錄互動, usable as table row actions, page header actions, or (with a record resolver) on board cards.
 */
class DealActions
{
    /**
     * Interaction types a person logs by hand; stage changes are logged by {@see DealService::changeStage()}.
     *
     * @return array<string, string>
     */
    public static function interactionTypes(): array
    {
        return collect([DealEventType::Meeting, DealEventType::ProposalSent, DealEventType::Note])
            ->mapWithKeys(fn (DealEventType $type): array => [$type->value => $type->getLabel()])
            ->all();
    }

    /**
     * The stage a deal most likely moves to next: the following open stage, or 成交 from 議價.
     */
    public static function nextStage(DealStage $stage): DealStage
    {
        return match ($stage) {
            DealStage::Lead => DealStage::Proposal,
            DealStage::Proposal => DealStage::Negotiation,
            default => DealStage::Won,
        };
    }

    public static function advanceStage(string $name = 'advanceStage'): Action
    {
        return Action::make($name)
            ->label('推進階段')
            ->icon(Heroicon::OutlinedArrowRightCircle)
            ->color('primary')
            ->modalHeading(fn (Deal $record): string => "推進階段：{$record->title}")
            ->modalWidth('md')
            ->fillForm(fn (Deal $record): array => [
                'stage' => self::nextStage($record->stage),
                'occurred_on' => today()->toDateString(),
            ])
            ->schema([
                Select::make('stage')
                    ->label('新階段')
                    ->options(DealStage::class)
                    ->required(),
                DatePicker::make('occurred_on')
                    ->label('日期')
                    ->required(),
                Textarea::make('note')
                    ->label('說明')
                    ->rows(3),
            ])
            ->action(function (Deal $record, array $data, Action $action): void {
                $stage = $data['stage'] instanceof DealStage ? $data['stage'] : DealStage::from($data['stage']);

                if ($stage === $record->stage) {
                    $action->failureNotificationTitle('階段沒有變更')->failure();

                    return;
                }

                app(DealService::class)->changeStage($record, $stage, $data['note'] ?? null, CarbonImmutable::parse($data['occurred_on']));

                $action->success();
            })
            ->successNotificationTitle('已更新階段');
    }

    public static function logInteraction(string $name = 'logInteraction'): Action
    {
        return Action::make($name)
            ->label('記錄互動')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->color('gray')
            ->modalHeading(fn (Deal $record): string => "記錄互動：{$record->title}")
            ->fillForm(fn (Deal $record): array => [
                'type' => DealEventType::Meeting->value,
                'occurred_on' => today()->toDateString(),
                'next_action' => $record->next_action,
                'next_action_on' => $record->next_action_on?->toDateString(),
            ])
            ->schema([
                Grid::make(2)->schema([
                    Select::make('type')
                        ->label('類型')
                        ->options(self::interactionTypes())
                        ->required(),
                    DatePicker::make('occurred_on')
                        ->label('日期')
                        ->required(),
                ]),
                Textarea::make('content')
                    ->label('內容')
                    ->rows(4)
                    ->required(),
                Grid::make(2)->schema([
                    TextInput::make('next_action')
                        ->label('下一步')
                        ->maxLength(255),
                    DatePicker::make('next_action_on')
                        ->label('下一步日期'),
                ]),
            ])
            ->action(function (Deal $record, array $data, Action $action): void {
                DB::transaction(function () use ($record, $data): void {
                    app(DealService::class)->logEvent(
                        $record,
                        DealEventType::from($data['type']),
                        $data['content'],
                        CarbonImmutable::parse($data['occurred_on']),
                    );

                    $record->fill([
                        'next_action' => $data['next_action'] ?? null,
                        'next_action_on' => $data['next_action_on'] ?? null,
                    ]);

                    if ($record->isDirty()) {
                        $record->save();
                    }
                });

                $action->success();
            })
            ->successNotificationTitle('已記錄');
    }
}
