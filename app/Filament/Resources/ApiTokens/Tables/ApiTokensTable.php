<?php

namespace App\Filament\Resources\ApiTokens\Tables;

use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokensTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('名稱')
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('abilities')
                    ->label('權限')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'write' ? 'warning' : 'info'),
                TextColumn::make('last_used_at')
                    ->label('最後使用')
                    ->dateTime()
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('從未使用')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->label('到期')
                    ->dateTime()
                    ->color(fn (PersonalAccessToken $record): ?string => $record->expires_at?->isPast() ? 'danger' : null)
                    ->placeholder('永不到期')
                    ->sortable(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->recordActions([
                DeleteAction::make()
                    ->label('撤銷')
                    ->modalHeading(fn (PersonalAccessToken $record): string => '撤銷 Token「'.$record->name.'」？')
                    ->modalDescription('撤銷後使用這把 token 的呼叫者會立即無法連線，此動作無法復原。')
                    ->modalSubmitActionLabel('撤銷')
                    ->successNotificationTitle('Token 已撤銷'),
            ])
            ->emptyStateHeading('尚未建立任何 API Token')
            ->emptyStateDescription('每個呼叫者（claude-cli、vault-agent、launchd-brief）各建立一把。');
    }
}
