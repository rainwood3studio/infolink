<?php

namespace App\Filament\Resources\ActionItems\Tables;

use App\Enums\ActionItemPriority;
use App\Enums\ActionItemStatus;
use App\Filament\Resources\ActionItems\ActionItemResource;
use App\Filament\Resources\ActionItems\Actions\ActionItemActions;
use App\Filament\Support\SourceFields;
use App\Models\ActionItem;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ActionItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('related'))
            ->columns([
                TextColumn::make('priority')
                    ->label('優先度')
                    ->badge()
                    ->sortable(),
                TextColumn::make('title')
                    ->label('標題')
                    ->description(fn (ActionItem $record): ?string => ActionItemResource::relatedLabel($record))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->sortable(),
                TextColumn::make('due_on')
                    ->label('到期日')
                    ->date()
                    ->placeholder('—')
                    ->color(fn (ActionItem $record): ?string => self::isLate($record) ? 'danger' : null)
                    ->sortable(),
                TextColumn::make('owner')
                    ->label('負責人')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('completed_at')
                    ->label('完成時間')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                SourceFields::column(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw('due_on is null')
                ->orderBy('due_on')
                ->orderBy('priority'))
            ->filters([
                SelectFilter::make('status')
                    ->label('狀態')
                    ->options(ActionItemStatus::class)
                    ->multiple()
                    ->default([ActionItemStatus::Todo->value, ActionItemStatus::Doing->value, ActionItemStatus::Waiting->value]),
                SelectFilter::make('priority')
                    ->label('優先度')
                    ->options(ActionItemPriority::class),
            ])
            ->recordActions([
                ActionItemActions::complete(),
                ActionItemActions::reopen(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function isLate(ActionItem $record): bool
    {
        return $record->due_on !== null
            && $record->due_on->lt(today())
            && ! in_array($record->status, [ActionItemStatus::Done, ActionItemStatus::Dropped], true);
    }
}
