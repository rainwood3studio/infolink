<?php

namespace App\Filament\Resources\GithubRepos\Tables;

use App\Models\GithubRepo;
use App\Models\Project;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GithubReposTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('commits'))
            ->columns([
                TextColumn::make('name')
                    ->label('Repo')
                    ->description(fn (GithubRepo $record): string => $record->owner)
                    ->url(fn (GithubRepo $record): string => $record->url(), shouldOpenInNewTab: true)
                    ->searchable(['name', 'full_name'])
                    ->sortable(),
                SelectColumn::make('project_id')
                    ->label('專案')
                    ->options(fn (): array => Project::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder('未指定'),
                IconColumn::make('is_archived')
                    ->label('封存')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('commits_count')
                    ->label('commits')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('pushed_at')
                    ->label('最後 push')
                    ->since()
                    ->dateTimeTooltip('Y-m-d H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('last_synced_at')
                    ->label('最後同步')
                    ->since()
                    ->dateTimeTooltip('Y-m-d H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('pushed_at', 'desc')
            ->filters([
                TernaryFilter::make('is_archived')
                    ->label('封存'),
            ]);
    }
}
