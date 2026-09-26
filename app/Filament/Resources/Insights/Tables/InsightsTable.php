<?php

namespace App\Filament\Resources\Insights\Tables;

use App\Enums\Category;
use App\Enums\InsightKind;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Filament\Resources\Insights\Actions\InsightActions;
use App\Filament\Support\SourceFields;
use App\Models\Insight;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class InsightsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('severity')
                    ->label('嚴重度')
                    ->badge()
                    ->sortable(),
                TextColumn::make('title')
                    ->label('標題')
                    ->description(fn (Insight $record): ?string => filled($record->body) ? Str::limit($record->body, 120) : null)
                    ->searchable(['title', 'body'])
                    ->wrap(),
                TextColumn::make('kind')
                    ->label('類型')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('category')
                    ->label('分類')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->label('最近發現')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
                TextColumn::make('first_seen_at')
                    ->label('首次發現')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                SourceFields::column(),
            ])
            ->defaultSort(fn (Builder $query): Builder => self::orderBySeverity($query)->orderByDesc('last_seen_at'))
            ->filters([
                SelectFilter::make('status')
                    ->label('狀態')
                    ->options(InsightStatus::class)
                    ->multiple()
                    ->default([InsightStatus::Open->value, InsightStatus::Acknowledged->value]),
                SelectFilter::make('severity')
                    ->label('嚴重度')
                    ->options(InsightSeverity::class)
                    ->multiple(),
                SelectFilter::make('kind')
                    ->label('類型')
                    ->options(InsightKind::class),
                SelectFilter::make('category')
                    ->label('分類')
                    ->options(Category::class),
            ])
            ->recordActions([
                InsightActions::acknowledge(),
                InsightActions::createActionItem(),
                ActionGroup::make([
                    InsightActions::resolve(),
                    InsightActions::dismiss(),
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Severity-first ordering for callers that want critical items on top.
     */
    public static function orderBySeverity(Builder $query): Builder
    {
        return $query->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end");
    }
}
