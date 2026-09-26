<?php

namespace App\Filament\Resources\ActionItems\Actions;

use App\Domain\Work\ActionItemService;
use App\Enums\ActionItemStatus;
use App\Models\ActionItem;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Quick status changes for action items. Usable as table row actions or page header actions.
 */
class ActionItemActions
{
    public static function complete(): Action
    {
        return Action::make('complete')
            ->label('完成')
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->visible(fn (ActionItem $record): bool => ! in_array($record->status, [ActionItemStatus::Done, ActionItemStatus::Dropped], true))
            ->action(function (ActionItem $record, Action $action): void {
                app(ActionItemService::class)->complete($record);

                $action->success();
            })
            ->successNotificationTitle('已完成');
    }

    public static function reopen(): Action
    {
        return Action::make('reopen')
            ->label('重新開啟')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (ActionItem $record): bool => in_array($record->status, [ActionItemStatus::Done, ActionItemStatus::Dropped], true))
            ->action(function (ActionItem $record, Action $action): void {
                app(ActionItemService::class)->reopen($record);

                $action->success();
            })
            ->successNotificationTitle('已重新開啟');
    }
}
