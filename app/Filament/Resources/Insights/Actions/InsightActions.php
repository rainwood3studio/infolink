<?php

namespace App\Filament\Resources\Insights\Actions;

use App\Domain\Insights\InsightService;
use App\Enums\ActionItemPriority;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Models\Insight;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * Status changes for insights and 「建立待辦」. Usable as table row actions or page header actions
 * (and by the dashboard's 今天要處理 list).
 */
class InsightActions
{
    public static function acknowledge(): Action
    {
        return Action::make('acknowledge')
            ->label('確認')
            ->icon(Heroicon::OutlinedEye)
            ->color('warning')
            ->visible(fn (Insight $record): bool => $record->status === InsightStatus::Open)
            ->action(function (Insight $record, Action $action): void {
                app(InsightService::class)->acknowledge($record);

                $action->success();
            })
            ->successNotificationTitle('已確認');
    }

    public static function resolve(): Action
    {
        return Action::make('resolve')
            ->label('解決')
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->visible(fn (Insight $record): bool => self::isUnresolved($record))
            ->schema([
                Textarea::make('note')
                    ->label('處理說明（選填）')
                    ->rows(2),
            ])
            ->modalSubmitActionLabel('解決')
            ->action(function (Insight $record, array $data, Action $action): void {
                app(InsightService::class)->resolve($record, filled($data['note'] ?? null) ? $data['note'] : null);

                $action->success();
            })
            ->successNotificationTitle('已解決');
    }

    public static function dismiss(): Action
    {
        return Action::make('dismiss')
            ->label('忽略')
            ->icon(Heroicon::OutlinedEyeSlash)
            ->color('gray')
            ->visible(fn (Insight $record): bool => self::isUnresolved($record))
            ->schema([
                Textarea::make('note')
                    ->label('忽略原因（選填）')
                    ->rows(2),
            ])
            ->modalSubmitActionLabel('忽略')
            ->action(function (Insight $record, array $data, Action $action): void {
                app(InsightService::class)->dismiss($record, filled($data['note'] ?? null) ? $data['note'] : null);

                $action->success();
            })
            ->successNotificationTitle('已忽略');
    }

    public static function createActionItem(): Action
    {
        return Action::make('createActionItem')
            ->label('建立待辦')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->color('primary')
            ->modalHeading('從注意事項建立待辦')
            ->fillForm(fn (Insight $record): array => [
                'title' => $record->title,
                'priority' => match ($record->severity) {
                    InsightSeverity::Critical => ActionItemPriority::P1,
                    InsightSeverity::Warning => ActionItemPriority::P2,
                    InsightSeverity::Info => ActionItemPriority::P3,
                },
            ])
            ->schema([
                TextInput::make('title')
                    ->label('標題')
                    ->required()
                    ->maxLength(255),
                Select::make('priority')
                    ->label('優先度')
                    ->options(ActionItemPriority::class)
                    ->required(),
                DatePicker::make('due_on')
                    ->label('到期日'),
                TextInput::make('owner')
                    ->label('負責人')
                    ->maxLength(255),
                Textarea::make('detail')
                    ->label('說明')
                    ->rows(3),
            ])
            ->action(function (Insight $record, array $data, Action $action): void {
                app(InsightService::class)->createActionItem($record, array_filter($data, filled(...)));

                $action->success();
            })
            ->successNotificationTitle('已建立待辦');
    }

    /**
     * @return list<Action>
     */
    public static function all(): array
    {
        return [
            self::acknowledge(),
            self::createActionItem(),
            self::resolve(),
            self::dismiss(),
        ];
    }

    protected static function isUnresolved(Insight $record): bool
    {
        return in_array($record->status, [InsightStatus::Open, InsightStatus::Acknowledged], true);
    }
}
