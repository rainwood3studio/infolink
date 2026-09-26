<?php

namespace App\Filament\Resources\NotificationLogs\Tables;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Filament\Resources\Insights\InsightResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\NotificationLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NotificationLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['insight', 'report']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('時間')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
                TextColumn::make('channel')
                    ->label('通道')
                    ->badge(),
                TextColumn::make('status')
                    ->label('狀態')
                    ->badge(),
                TextColumn::make('subject')
                    ->label('對象')
                    ->state(fn (NotificationLog $record): ?string => self::subject($record))
                    ->url(fn (NotificationLog $record): ?string => self::subjectUrl($record))
                    ->placeholder('—')
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('message')
                    ->label('內容')
                    ->state(fn (NotificationLog $record): ?string => self::message($record))
                    ->tooltip(fn (NotificationLog $record): ?string => self::message($record))
                    ->limit(80)
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('deliver_after')
                    ->label('延後至')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('sent_at')
                    ->label('送出')
                    ->dateTime('Y-m-d H:i:s')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('error')
                    ->label('錯誤')
                    ->color('danger')
                    ->limit(120)
                    ->tooltip(fn (NotificationLog $record): ?string => $record->error)
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->filters([
                SelectFilter::make('channel')
                    ->label('通道')
                    ->options(NotificationChannel::class),
                SelectFilter::make('status')
                    ->label('狀態')
                    ->options(NotificationStatus::class),
            ]);
    }

    public static function subject(NotificationLog $record): ?string
    {
        return match (true) {
            $record->insight !== null => '注意事項：'.$record->insight->title,
            $record->report !== null => '報告：'.$record->report->title,
            default => null,
        };
    }

    public static function subjectUrl(NotificationLog $record): ?string
    {
        return match (true) {
            $record->insight !== null => InsightResource::getUrl('view', ['record' => $record->insight]),
            $record->report !== null => ReportResource::getUrl('view', ['record' => $record->report]),
            default => null,
        };
    }

    public static function message(NotificationLog $record): ?string
    {
        $payload = $record->payload ?? [];

        return $payload['text'] ?? collect([$payload['title'] ?? null, $payload['body'] ?? null])->filter()->implode("\n") ?: null;
    }
}
