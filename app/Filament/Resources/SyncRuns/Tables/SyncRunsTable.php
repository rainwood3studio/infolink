<?php

namespace App\Filament\Resources\SyncRuns\Tables;

use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Models\SyncRun;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SyncRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('job')
                    ->label('工作')
                    ->badge(),
                TextColumn::make('status')
                    ->label('狀態')
                    ->badge(),
                TextColumn::make('started_at')
                    ->label('開始')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
                TextColumn::make('duration')
                    ->label('耗時')
                    ->state(fn (SyncRun $record): ?string => self::duration($record))
                    ->placeholder('—')
                    ->alignEnd(),
                TextColumn::make('stats')
                    ->label('統計')
                    ->state(fn (SyncRun $record): ?string => self::stats($record))
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('error')
                    ->label('錯誤')
                    ->color('danger')
                    ->limit(120)
                    ->tooltip(fn (SyncRun $record): ?string => $record->error)
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('started_at')->orderByDesc('id'))
            ->filters([
                SelectFilter::make('job')
                    ->label('工作')
                    ->options(SyncJob::class),
                SelectFilter::make('status')
                    ->label('狀態')
                    ->options(SyncStatus::class),
            ]);
    }

    public static function duration(SyncRun $record): ?string
    {
        if ($record->finished_at === null) {
            return null;
        }

        $seconds = (int) $record->started_at->diffInSeconds($record->finished_at, absolute: true);

        return $seconds < 60
            ? $seconds.' 秒'
            : intdiv($seconds, 60).' 分 '.($seconds % 60).' 秒';
    }

    public static function stats(SyncRun $record): ?string
    {
        if (blank($record->stats)) {
            return null;
        }

        return collect($record->stats)
            ->map(fn (mixed $value, string|int $key): string => $key.': '.match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                default => json_encode($value, JSON_UNESCAPED_UNICODE),
            })
            ->implode('、');
    }
}
