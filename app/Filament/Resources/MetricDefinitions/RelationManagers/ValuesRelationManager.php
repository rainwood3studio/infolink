<?php

namespace App\Filament\Resources\MetricDefinitions\RelationManagers;

use App\Enums\MetricUnit;
use App\Filament\Resources\MetricDefinitions\Actions\RecordMetricValueAction;
use App\Filament\Support\Money;
use App\Filament\Support\SourceFields;
use App\Models\MetricDefinition;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * History of a metric's values. New values go through 「手動補值」 (MetricRecorder), never a raw create.
 */
class ValuesRelationManager extends RelationManager
{
    protected static string $relationship = 'values';

    protected static ?string $title = '數值紀錄';

    protected static ?string $modelLabel = '數值';

    protected static ?string $pluralModelLabel = '數值';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        /** @var MetricDefinition $definition */
        $definition = $this->getOwnerRecord();

        return $table
            ->columns([
                TextColumn::make('period_start')
                    ->label('期間')
                    ->date()
                    ->sortable(),
                TextColumn::make('dimension')
                    ->label('維度')
                    ->placeholder('整體')
                    ->searchable(),
                TextColumn::make('value')
                    ->label('數值')
                    ->formatStateUsing(fn (string|float|null $state): ?string => self::formatValue($definition->unit, $state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('source')
                    ->label('來源')
                    ->formatStateUsing(fn ($state): ?string => SourceFields::label($state))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('actor')
                    ->label('寫入者'),
                TextColumn::make('notes')
                    ->label('備註')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('寫入時間')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('period_start', 'desc')
            ->headerActions([
                RecordMetricValueAction::make(fn (): MetricDefinition => $definition),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->visible(fn (): bool => $definition->calculator === null),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->visible(fn (): bool => $definition->calculator === null),
            ]);
    }

    public static function formatValue(MetricUnit $unit, string|float|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (float) $value;

        return match ($unit) {
            MetricUnit::Twd => Money::format(round($value)),
            MetricUnit::Ratio => number_format($value * 100, 1).'%',
            MetricUnit::Count => self::trimmedNumber($value),
            default => self::trimmedNumber($value).' '.$unit->getLabel(),
        };
    }

    protected static function trimmedNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }
}
