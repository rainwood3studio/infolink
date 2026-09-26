<?php

namespace App\Filament\Resources\CostBaselines\Tables;

use App\Filament\Support\Money;
use App\Filament\Support\SourceFields;
use App\Models\CostBaseline;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CostBaselinesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('effective_from')
                    ->label('生效日')
                    ->date()
                    ->sortable(),
                Money::column(TextColumn::make('monthly_cost'))
                    ->label('每月固定成本')
                    ->sortable(),
                TextColumn::make('breakdown')
                    ->label('細項')
                    ->state(fn (CostBaseline $record): array => collect($record->breakdown ?? [])
                        ->map(fn (mixed $amount, string $item): string => $item.'：'.Money::format((int) $amount))
                        ->values()
                        ->all())
                    ->listWithLineBreaks()
                    ->limitList(4)
                    ->expandableLimitedList(),
                SourceFields::column(),
                TextColumn::make('actor')
                    ->label('寫入者')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('effective_from', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
