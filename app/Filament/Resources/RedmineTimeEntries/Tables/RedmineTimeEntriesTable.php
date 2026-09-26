<?php

namespace App\Filament\Resources\RedmineTimeEntries\Tables;

use App\Models\RedmineIssue;
use App\Models\RedmineTimeEntry;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RedmineTimeEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('issue'))
            ->columns([
                TextColumn::make('spent_on')
                    ->label('日期')
                    ->date()
                    ->sortable(),
                TextColumn::make('user_name')
                    ->label('人員')
                    ->sortable(),
                TextColumn::make('project_identifier')
                    ->label('專案')
                    ->formatStateUsing(fn (RedmineTimeEntry $record): string => $record->issue?->project_name ?? $record->project_identifier)
                    ->sortable(),
                TextColumn::make('issue_id')
                    ->label('議題')
                    ->prefix('#')
                    ->description(fn (RedmineTimeEntry $record): ?string => $record->issue?->subject)
                    ->url(fn (RedmineTimeEntry $record): ?string => $record->issue_id === null
                        ? null
                        : (new RedmineIssue(['id' => $record->issue_id]))->url(), shouldOpenInNewTab: true)
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('activity')
                    ->label('活動'),
                TextColumn::make('hours')
                    ->label('時數')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()->label('合計')->numeric(decimalPlaces: 2)),
                TextColumn::make('comments')
                    ->label('說明')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('spent_on')->orderByDesc('id'))
            ->filters([
                SelectFilter::make('user_name')
                    ->label('人員')
                    ->options(fn (): array => self::distinctOptions('user_name'))
                    ->multiple(),
                SelectFilter::make('project_identifier')
                    ->label('專案')
                    ->options(fn (): array => self::distinctOptions('project_identifier'))
                    ->multiple(),
                SelectFilter::make('activity')
                    ->label('活動')
                    ->options(fn (): array => self::distinctOptions('activity'))
                    ->multiple(),
                Filter::make('spent_on')
                    ->label('日期區間')
                    ->schema([
                        DatePicker::make('from')->label('從'),
                        DatePicker::make('until')->label('到'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('spent_on', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('spent_on', '<=', $date))),
            ]);
    }

    /**
     * @return array<string, string>
     */
    protected static function distinctOptions(string $column): array
    {
        return RedmineTimeEntry::query()
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->all();
    }
}
