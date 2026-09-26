<?php

namespace App\Filament\Resources\Deals\Tables;

use App\Enums\DealStage;
use App\Filament\Resources\Deals\Actions\DealActions;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Support\Money;
use App\Filament\Support\SourceFields;
use App\Models\Deal;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DealsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('customer')
                ->withMax('events', 'occurred_on'))
            ->columns([
                TextColumn::make('party_name')
                    ->label('客戶')
                    ->description(fn (Deal $record): ?string => $record->customer_id === null ? '潛在客戶' : null)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query): Builder => $query
                        ->where('prospect_name', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn (Builder $query): Builder => $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('short_name', 'like', "%{$search}%")))),
                TextColumn::make('title')
                    ->label('標題')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('stage')
                    ->label('階段')
                    ->badge()
                    ->sortable(),
                Money::column(TextColumn::make('amount_untaxed'))
                    ->label('金額（未稅）')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('probability')
                    ->label('機率')
                    ->suffix('%')
                    ->alignEnd()
                    ->sortable(),
                Money::column(TextColumn::make('weighted_amount'))
                    ->label('加權')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw('coalesce(amount_untaxed, 0) * probability '.($direction === 'desc' ? 'desc' : 'asc'))),
                Money::column(TextColumn::make('recurring_monthly'))
                    ->label('月經常性')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('expected_close_on')
                    ->label('預計成交')
                    ->date()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('next_action')
                    ->label('下一步')
                    ->placeholder('沒有下一步')
                    ->description(fn (Deal $record): ?string => $record->next_action_on?->format('Y-m-d') ?? (filled($record->next_action) ? '未排日期' : null))
                    ->color(fn (Deal $record): ?string => DealResource::needsNextAction($record) ? 'danger' : null)
                    ->wrap(),
                TextColumn::make('events_max_occurred_on')
                    ->label('最近互動')
                    ->date()
                    ->placeholder('—')
                    ->sortable(),
                SourceFields::column(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw('next_action_on is null')
                ->orderBy('next_action_on')
                ->orderByDesc('id'))
            ->filters([
                Filter::make('open')
                    ->label('只看進行中')
                    ->toggle()
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->whereIn('stage', DealStage::open())),
                SelectFilter::make('stage')
                    ->label('階段')
                    ->options(DealStage::class)
                    ->multiple(),
                Filter::make('no_next_action')
                    ->label('沒有下一步（或已過期）')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->whereIn('stage', DealStage::open())
                        ->where(fn (Builder $query): Builder => $query
                            ->whereNull('next_action_on')
                            ->orWhereDate('next_action_on', '<', today()))),
            ])
            ->recordActions([
                DealActions::advanceStage()->visible(fn (Deal $record): bool => ! $record->stage->isClosed()),
                ActionGroup::make([
                    DealActions::logInteraction(),
                    EditAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
