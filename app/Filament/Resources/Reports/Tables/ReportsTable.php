<?php

namespace App\Filament\Resources\Reports\Tables;

use App\Enums\ReportType;
use App\Filament\Support\SourceFields;
use App\Models\Report;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label('類型')
                    ->badge(),
                TextColumn::make('period_start')
                    ->label('期間')
                    ->formatStateUsing(fn (Report $record): string => self::period($record))
                    ->sortable(),
                TextColumn::make('title')
                    ->label('標題')
                    ->weight('bold')
                    ->searchable()
                    ->wrap(),
                SourceFields::column()->toggleable(),
                TextColumn::make('actor')
                    ->label('寫入者')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('period_start')->orderByDesc('id'))
            ->filters([
                SelectFilter::make('type')
                    ->label('類型')
                    ->options(ReportType::class),
                Filter::make('period')
                    ->label('期間')
                    ->schema([
                        DatePicker::make('from')->label('期間起'),
                        DatePicker::make('until')->label('期間迄'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $from): Builder => $query->whereDate('period_start', '>=', $from))
                        ->when($data['until'] ?? null, fn (Builder $query, string $until): Builder => $query->whereDate('period_start', '<=', $until)))
                    ->indicateUsing(fn (array $data): ?string => match (true) {
                        filled($data['from'] ?? null) && filled($data['until'] ?? null) => '期間 '.$data['from'].' ~ '.$data['until'],
                        filled($data['from'] ?? null) => '期間起 '.$data['from'],
                        filled($data['until'] ?? null) => '期間迄 '.$data['until'],
                        default => null,
                    }),
            ]);
    }

    /**
     * `2026-09-22 ~ 2026-09-28`, or a single date when the report covers one day.
     */
    public static function period(Report $record): string
    {
        $start = $record->period_start->format('Y-m-d');

        if ($record->period_end === null || $record->period_end->isSameDay($record->period_start)) {
            return $start;
        }

        return $start.' ~ '.$record->period_end->format('Y-m-d');
    }
}
