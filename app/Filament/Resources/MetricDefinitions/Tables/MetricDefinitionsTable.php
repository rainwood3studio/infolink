<?php

namespace App\Filament\Resources\MetricDefinitions\Tables;

use App\Enums\Category;
use App\Filament\Resources\MetricDefinitions\Actions\RecordMetricValueAction;
use App\Models\MetricDefinition;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MetricDefinitionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('名稱')
                    ->description(fn (MetricDefinition $record): string => $record->key)
                    ->searchable(['name', 'key']),
                TextColumn::make('category')
                    ->label('分類')
                    ->badge()
                    ->sortable(),
                TextColumn::make('unit')
                    ->label('單位'),
                TextColumn::make('period_type')
                    ->label('週期'),
                TextColumn::make('better')
                    ->label('方向')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('warn_threshold')
                    ->label('警告門檻')
                    ->numeric(maxDecimalPlaces: 4)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('critical_threshold')
                    ->label('嚴重門檻')
                    ->numeric(maxDecimalPlaces: 4)
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('is_calculated')
                    ->label('系統計算')
                    ->state(fn (MetricDefinition $record): bool => $record->calculator !== null)
                    ->boolean(),
                IconColumn::make('is_pinned')
                    ->label('釘選')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('values_count')
                    ->label('筆數')
                    ->counts('values')
                    ->alignEnd()
                    ->toggleable(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('sort')->orderBy('key'))
            ->filters([
                SelectFilter::make('category')
                    ->label('分類')
                    ->options(Category::class),
                TernaryFilter::make('is_pinned')
                    ->label('釘選'),
                TernaryFilter::make('calculator')
                    ->label('系統計算')
                    ->nullable(),
            ])
            ->recordActions([
                RecordMetricValueAction::make(),
                EditAction::make(),
            ]);
    }
}
