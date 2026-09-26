<?php

namespace App\Filament\Resources\RedmineIssues\Tables;

use App\Models\RedmineIssue;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RedmineIssuesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->prefix('#')
                    ->url(fn (RedmineIssue $record): string => $record->url(), shouldOpenInNewTab: true)
                    ->color('primary')
                    ->searchable(query: fn (Builder $query, string $search): Builder => ctype_digit($search)
                        ? $query->orWhere('id', (int) $search)
                        : $query)
                    ->sortable(),
                TextColumn::make('project_name')
                    ->label('專案')
                    ->sortable()
                    ->wrap(),
                TextColumn::make('tracker')
                    ->label('追蹤標籤')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->color(fn (RedmineIssue $record): string => self::statusColor($record))
                    ->sortable(),
                TextColumn::make('priority')
                    ->label('優先')
                    ->toggleable(),
                TextColumn::make('assignee_name')
                    ->label('被分派者')
                    ->placeholder('未指派')
                    ->sortable(),
                TextColumn::make('subject')
                    ->label('主旨')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('updated_on')
                    ->label('最後更新')
                    ->date()
                    ->description(fn (RedmineIssue $record): string => self::daysAgo($record).' 天前')
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('預計完成')
                    ->date()
                    ->placeholder('—')
                    ->color(fn (RedmineIssue $record): ?string => self::isOverdue($record) ? 'danger' : null)
                    ->sortable(),
                TextColumn::make('created_on')
                    ->label('建立')
                    ->date()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('updated_on', 'desc')
            ->filters([
                SelectFilter::make('project_identifier')
                    ->label('專案')
                    ->options(fn (): array => RedmineIssue::query()
                        ->distinct()
                        ->orderBy('project_name')
                        ->pluck('project_name', 'project_identifier')
                        ->all())
                    ->multiple(),
                SelectFilter::make('status')
                    ->label('狀態')
                    ->options(fn (): array => self::distinctOptions('status'))
                    ->multiple(),
                SelectFilter::make('assignee_name')
                    ->label('被分派者')
                    ->options(fn (): array => self::distinctOptions('assignee_name'))
                    ->multiple(),
                Filter::make('open')
                    ->label('只看未結案')
                    ->toggle()
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->where('is_closed', false)),
                SelectFilter::make('stalled')
                    ->label('停滯')
                    ->options([30 => '停滯 > 30 天', 90 => '停滯 > 90 天'])
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->where('is_closed', false)->where('updated_on', '<', now()->subDays((int) $data['value']))),
                Filter::make('verifying_others')
                    ->label('驗證中（非文豪）')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => self::verifyingOthers($query)),
            ])
            ->recordUrl(fn (RedmineIssue $record): string => $record->url(), shouldOpenInNewTab: true);
    }

    /**
     * Issues in 驗證中 assigned to someone other than the final acceptor (off the acceptance flow).
     *
     * @param  Builder<RedmineIssue>  $query
     * @return Builder<RedmineIssue>
     */
    public static function verifyingOthers(Builder $query): Builder
    {
        $acceptor = (string) config('services.redmine.acceptor_name');

        return $query
            ->where('status', RedmineIssue::STATUS_VERIFYING)
            ->whereNotNull('assignee_name')
            ->when($acceptor !== '', fn (Builder $query): Builder => $query->where('assignee_name', 'not like', $acceptor.'%'));
    }

    public static function isOverdue(RedmineIssue $record): bool
    {
        return ! $record->is_closed && $record->due_date !== null && $record->due_date->isBefore(today());
    }

    public static function daysAgo(RedmineIssue $record): int
    {
        return (int) $record->updated_on->copy()->startOfDay()->diffInDays(today());
    }

    protected static function statusColor(RedmineIssue $record): string
    {
        return match (true) {
            $record->is_closed => 'gray',
            $record->status === RedmineIssue::STATUS_VERIFYING => RedmineIssue::isAcceptor($record->assignee_name) ? 'info' : 'warning',
            default => 'primary',
        };
    }

    /**
     * @return array<string, string>
     */
    protected static function distinctOptions(string $column): array
    {
        return RedmineIssue::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->all();
    }
}
